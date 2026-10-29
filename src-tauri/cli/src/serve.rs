//! Read-only local web UI. Binds only loopback and this machine's Tailscale
//! addresses, never 0.0.0.0, and answers only requests addressed to an IP or
//! localhost (blocks DNS rebinding from web pages).
//!
//! A deliberately small HTTP/1.0 server on std: one short-lived thread per
//! connection (capped), read/write timeouts, and the connection closes after
//! each response, so slow or idle clients cannot pile up.

use metr_lib::headless::{self, GroupBy, Paths, UsageQuery};
use serde_json::Value;
use std::collections::HashMap;
use std::io::{BufRead, BufReader, Read, Write};
use std::net::{IpAddr, SocketAddr, TcpListener, TcpStream};
use std::process::Command;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::Arc;
use std::thread::JoinHandle;
use std::time::Duration;

const PAGE: &str = include_str!("ui.html");
const MAX_CONNECTIONS: usize = 32;
const IO_TIMEOUT: Duration = Duration::from_secs(10);
const MAX_HEAD_BYTES: usize = 16 * 1024;

/// Loopback, Tailscale IPv4 (100.64.0.0/10) or Tailscale IPv6 (fd7a:115c:a1e0::/48).
pub fn is_allowed(ip: IpAddr) -> bool {
    match ip {
        IpAddr::V4(v4) => {
            let [a, b, ..] = v4.octets();
            v4.is_loopback() || (a == 100 && (64..128).contains(&b))
        }
        IpAddr::V6(v6) => {
            let s = v6.segments();
            v6.is_loopback() || (s[0] == 0xfd7a && s[1] == 0x115c && s[2] == 0xa1e0)
        }
    }
}

/// This machine's Tailscale addresses (`tailscale ip`), empty if Tailscale
/// is not installed or not up.
fn tailscale_ips() -> Vec<IpAddr> {
    let Ok(output) = Command::new("tailscale").arg("ip").output() else {
        return Vec::new();
    };
    if !output.status.success() {
        return Vec::new();
    }
    String::from_utf8_lossy(&output.stdout)
        .lines()
        .filter_map(|line| line.trim().parse().ok())
        .filter(|ip| is_allowed(*ip))
        .collect()
}

/// `--listen` values (IP or IP:port), or 127.0.0.1 plus the Tailscale IPv4.
/// A non-loopback address must be one of this machine's Tailscale addresses
/// (other 100.64.0.0/10 users, like carrier-grade NAT, are refused).
pub fn resolve_addresses(listen: &[String], port: u16) -> Result<Vec<SocketAddr>, String> {
    resolve_with(listen, port, &tailscale_ips())
}

fn resolve_with(
    listen: &[String],
    port: u16,
    tailscale: &[IpAddr],
) -> Result<Vec<SocketAddr>, String> {
    let mut addresses = Vec::new();
    if listen.is_empty() {
        addresses.push(SocketAddr::from(([127, 0, 0, 1], port)));
        addresses.extend(
            tailscale
                .iter()
                .find(|ip| ip.is_ipv4())
                .map(|ip| SocketAddr::new(*ip, port)),
        );
    }
    for value in listen {
        let address = value
            .parse::<SocketAddr>()
            .or_else(|_| value.parse::<IpAddr>().map(|ip| SocketAddr::new(ip, port)))
            .map_err(|_| format!("invalid listen address '{value}'"))?;
        let ip = address.ip();
        if !is_allowed(ip) || !(ip.is_loopback() || tailscale.contains(&ip)) {
            return Err(format!(
                "refusing to listen on {ip}: only loopback and this machine's Tailscale addresses are allowed"
            ));
        }
        addresses.push(address);
    }
    Ok(addresses)
}

/// Keeps one listener per address and retries the ones that could not bind
/// yet (the Tailscale IP appears only once tailscaled is up).
pub fn serve_forever(paths: Paths, listen: Vec<String>, port: u16) -> Result<(), String> {
    resolve_addresses(&listen, port)?; // reject bad --listen values up front
    let active = Arc::new(AtomicUsize::new(0));
    let mut running: HashMap<SocketAddr, JoinHandle<()>> = HashMap::new();
    loop {
        running.retain(|_, handle| !handle.is_finished());
        match resolve_addresses(&listen, port) {
            Ok(addresses) => {
                for address in addresses {
                    if running.contains_key(&address) {
                        continue;
                    }
                    match TcpListener::bind(address) {
                        Ok(listener) => {
                            println!("web UI on http://{address}/");
                            let (paths, active) = (paths.clone(), active.clone());
                            running.insert(
                                address,
                                std::thread::spawn(move || accept_loop(listener, paths, active)),
                            );
                        }
                        Err(error) => eprintln!("cannot listen on {address} yet: {error}"),
                    }
                }
            }
            Err(error) => eprintln!("{error}"),
        }
        std::thread::sleep(Duration::from_secs(60));
    }
}

fn accept_loop(listener: TcpListener, paths: Paths, active: Arc<AtomicUsize>) {
    for stream in listener.incoming() {
        let Ok(stream) = stream else { continue };
        if active.fetch_add(1, Ordering::SeqCst) >= MAX_CONNECTIONS {
            active.fetch_sub(1, Ordering::SeqCst);
            continue; // dropped: closes the connection
        }
        let (paths, active) = (paths.clone(), active.clone());
        std::thread::spawn(move || {
            let _ = handle_connection(stream, &paths);
            active.fetch_sub(1, Ordering::SeqCst);
        });
    }
}

struct Response {
    status: u16,
    content_type: &'static str,
    body: String,
}

fn reply(status: u16, content_type: &'static str, body: impl Into<String>) -> Response {
    Response {
        status,
        content_type,
        body: body.into(),
    }
}

fn json_reply(result: Result<Value, String>) -> Response {
    match result {
        Ok(value) => reply(200, "application/json", value.to_string()),
        Err(error) => reply(
            500,
            "application/json",
            serde_json::json!({ "error": error }).to_string(),
        ),
    }
}

fn bad_request(error: String) -> Response {
    reply(
        400,
        "application/json",
        serde_json::json!({ "error": error }).to_string(),
    )
}

fn handle_connection(stream: TcpStream, paths: &Paths) -> std::io::Result<()> {
    stream.set_read_timeout(Some(IO_TIMEOUT))?;
    stream.set_write_timeout(Some(IO_TIMEOUT))?;
    let mut reader = BufReader::new(stream.try_clone()?);
    let mut head = Vec::new();
    // Read the request line and headers (no bodies: the UI is GET only).
    loop {
        let mut line = Vec::new();
        let read = (&mut reader)
            .take((MAX_HEAD_BYTES - head.len().min(MAX_HEAD_BYTES)) as u64 + 1)
            .read_until(b'\n', &mut line)?;
        if read == 0 || head.len() + line.len() > MAX_HEAD_BYTES {
            return write_response(&stream, reply(431, "text/plain", "Request too large."));
        }
        let end = line == b"\r\n" || line == b"\n";
        head.extend_from_slice(&line);
        if end {
            break;
        }
    }
    let head = String::from_utf8_lossy(&head);
    let mut lines = head.lines();
    let request_line = lines.next().unwrap_or("");
    let mut parts = request_line.split_whitespace();
    let (method, target) = (parts.next().unwrap_or(""), parts.next().unwrap_or(""));
    let hosts: Vec<&str> = lines
        .filter_map(|l| l.split_once(':'))
        .filter(|(name, _)| name.trim().eq_ignore_ascii_case("host"))
        .map(|(_, value)| value.trim())
        .collect();
    write_response(&stream, respond(method, target, &hosts, paths))
}

fn write_response(mut stream: &TcpStream, response: Response) -> std::io::Result<()> {
    let reason = match response.status {
        200 => "OK",
        400 => "Bad Request",
        404 => "Not Found",
        405 => "Method Not Allowed",
        421 => "Misdirected Request",
        431 => "Request Header Fields Too Large",
        _ => "Internal Server Error",
    };
    write!(
        stream,
        "HTTP/1.0 {} {reason}\r\nContent-Type: {}\r\nContent-Length: {}\r\nCache-Control: no-store\r\n\
         X-Content-Type-Options: nosniff\r\nX-Frame-Options: DENY\r\nConnection: close\r\n\r\n",
        response.status,
        response.content_type,
        response.body.len()
    )?;
    stream.write_all(response.body.as_bytes())?;
    stream.flush()
}

/// Exactly one Host, and it must be an IP literal or localhost, so a web page
/// that rebinds its DNS name to 127.0.0.1 cannot read the data.
fn host_is_allowed(hosts: &[&str]) -> bool {
    let [host] = hosts else { return false };
    let name = if let Some(rest) = host.strip_prefix('[') {
        rest.split(']').next().unwrap_or("")
    } else {
        host.rsplit_once(':').map(|(name, _)| name).unwrap_or(host)
    };
    name.eq_ignore_ascii_case("localhost") || name.parse::<IpAddr>().is_ok()
}

fn respond(method: &str, target: &str, hosts: &[&str], paths: &Paths) -> Response {
    if !host_is_allowed(hosts) {
        return reply(421, "text/plain", "Use the IP address or localhost.");
    }
    if method != "GET" {
        return reply(405, "text/plain", "Read-only.");
    }
    let (path, query) = target.split_once('?').unwrap_or((target, ""));
    let params = parse_query(query);
    match path {
        "/" => reply(200, "text/html; charset=utf-8", PAGE),
        "/api/status" => json_reply(with_conn(paths, |conn| {
            let mut status = headless::status(conn, paths)?;
            status["last_run"] = crate::schedule::last_run(paths).unwrap_or(Value::Null);
            Ok(status)
        })),
        "/api/usage" => match usage_query(&params) {
            Ok(query) => json_reply(with_conn(paths, |conn| {
                Ok(Value::Array(headless::usage(conn, &query)?))
            })),
            Err(error) => bad_request(error),
        },
        _ => reply(404, "text/plain", "Not found."),
    }
}

fn usage_query(params: &HashMap<String, String>) -> Result<UsageQuery, String> {
    let bound = |key: &str| {
        params
            .get(key)
            .map(|v| headless::parse_bound(v))
            .transpose()
    };
    let provider = params.get("provider").cloned();
    if provider.as_deref().is_some_and(|p| {
        !p.chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_')
    }) {
        return Err("invalid provider".to_string());
    }
    Ok(UsageQuery {
        since: bound("since")?,
        until: bound("until")?,
        provider,
        group_by: params
            .get("by")
            .map(|v| v.parse::<GroupBy>())
            .transpose()?
            .unwrap_or(GroupBy::Provider),
    })
}

fn with_conn(
    paths: &Paths,
    f: impl FnOnce(&headless::Connection) -> Result<Value, String>,
) -> Result<Value, String> {
    let conn = headless::open_connection(&paths.db)?;
    f(&conn)
}

fn percent_decode(value: &str) -> String {
    let hex = |b: u8| (b as char).to_digit(16).map(|d| d as u8);
    let bytes = value.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        match bytes[i] {
            b'+' => out.push(b' '),
            b'%' if i + 2 < bytes.len() => match (hex(bytes[i + 1]), hex(bytes[i + 2])) {
                (Some(high), Some(low)) => {
                    out.push(high * 16 + low);
                    i += 3;
                    continue;
                }
                _ => out.push(b'%'),
            },
            byte => out.push(byte),
        }
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

fn parse_query(query: &str) -> HashMap<String, String> {
    query
        .split('&')
        .filter_map(|pair| pair.split_once('='))
        .map(|(key, value)| (percent_decode(key), percent_decode(value)))
        .filter(|(_, value)| !value.is_empty())
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_loopback_and_own_tailscale_addresses_are_allowed() {
        for ok in [
            "127.0.0.1",
            "::1",
            "100.115.64.43",
            "100.64.0.1",
            "100.127.255.254",
            "fd7a:115c:a1e0::1",
        ] {
            assert!(is_allowed(ok.parse().unwrap()), "{ok}");
        }
        for bad in [
            "0.0.0.0",
            "::",
            "192.168.1.10",
            "100.63.0.1",
            "100.128.0.1",
            "10.0.0.1",
            "fd00::1",
        ] {
            assert!(!is_allowed(bad.parse().unwrap()), "{bad}");
        }
        let own: Vec<IpAddr> = vec![
            "100.115.64.43".parse().unwrap(),
            "fd7a:115c:a1e0::5".parse().unwrap(),
        ];
        assert!(resolve_with(&["0.0.0.0".to_string()], 8765, &own).is_err());
        assert!(resolve_with(&["[::]:80".to_string()], 8765, &own).is_err());
        // In the Tailscale range but not this machine's address (e.g. CGNAT).
        assert!(resolve_with(&["100.100.1.1".to_string()], 8765, &own).is_err());
        assert_eq!(
            resolve_with(&["100.115.64.43".to_string()], 8765, &own).unwrap(),
            vec!["100.115.64.43:8765".parse().unwrap()]
        );
        assert_eq!(
            resolve_with(&[], 8765, &own).unwrap(),
            vec![
                "127.0.0.1:8765".parse().unwrap(),
                "100.115.64.43:8765".parse().unwrap()
            ]
        );
    }

    #[test]
    fn queries_are_percent_decoded_and_validated() {
        let params = parse_query("by=d%61y&since=2026-10-28T01%3A00%3A00%2B02%3A00&x=");
        assert_eq!(params["by"], "day");
        assert_eq!(params["since"], "2026-10-28T01:00:00+02:00");
        assert!(!params.contains_key("x"));
        let query = usage_query(&params).unwrap();
        assert_eq!(query.since.as_deref(), Some("2026-10-27T23:00:00"));
        assert!(usage_query(&parse_query("since=zzz")).is_err());
        assert!(usage_query(&parse_query("provider=%3Cscript%3E")).is_err());
        assert_eq!(percent_decode("100%"), "100%");
        assert_eq!(percent_decode("%zz"), "%zz");
    }

    #[test]
    fn host_header_must_be_a_single_ip_or_localhost() {
        assert!(host_is_allowed(&["127.0.0.1:8765"]));
        assert!(host_is_allowed(&["[::1]:8765"]));
        assert!(host_is_allowed(&["localhost"]));
        assert!(!host_is_allowed(&["evil.example:8765"]));
        assert!(!host_is_allowed(&["127.0.0.1.nip.io"]));
        assert!(!host_is_allowed(&[]));
        assert!(!host_is_allowed(&["127.0.0.1", "evil.example"]));
    }

    #[test]
    fn idle_and_oversized_requests_are_closed() {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let address = listener.local_addr().unwrap();
        let root = std::env::temp_dir().join(format!("metr-serve-{}", std::process::id()));
        let paths = Paths {
            db: root.join("metr.db"),
            token: root.join("token"),
            last_run: root.join("last-run.json"),
            lock: root.join("metr.lock"),
        };
        std::thread::spawn(move || accept_loop(listener, paths, Arc::new(AtomicUsize::new(0))));
        let mut big = TcpStream::connect(address).unwrap();
        let _ = big.write_all(
            format!("GET /?{} HTTP/1.1\r\n", "a".repeat(MAX_HEAD_BYTES + 10)).as_bytes(),
        );
        let mut answer = String::new();
        let _ = std::io::Read::read_to_string(&mut big, &mut answer);
        assert!(answer.starts_with("HTTP/1.0 431"), "{answer}");
        let mut ok = TcpStream::connect(address).unwrap();
        ok.write_all(b"GET /nope HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n")
            .unwrap();
        let mut answer = String::new();
        std::io::Read::read_to_string(&mut ok, &mut answer).unwrap();
        assert!(answer.starts_with("HTTP/1.0 404"), "{answer}");
    }
}
