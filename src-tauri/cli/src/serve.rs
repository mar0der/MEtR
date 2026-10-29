//! Read-only local web UI. Binds only loopback and Tailscale addresses,
//! never 0.0.0.0, and answers only requests addressed to an IP or localhost
//! (blocks DNS rebinding from web pages).

use metr_lib::headless::{self, GroupBy, Paths, UsageQuery};
use serde_json::Value;
use std::collections::HashMap;
use std::net::{IpAddr, SocketAddr};
use std::process::Command;
use std::thread::JoinHandle;
use std::time::Duration;

const PAGE: &str = include_str!("ui.html");

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

fn tailscale_ipv4() -> Option<IpAddr> {
    let output = Command::new("tailscale").args(["ip", "-4"]).output().ok()?;
    if !output.status.success() {
        return None;
    }
    String::from_utf8_lossy(&output.stdout)
        .lines()
        .next()?
        .trim()
        .parse()
        .ok()
        .filter(|ip| is_allowed(*ip))
}

/// `--listen` values (IP or IP:port), or 127.0.0.1 plus the Tailscale IP.
pub fn resolve_addresses(listen: &[String], port: u16) -> Result<Vec<SocketAddr>, String> {
    let mut addresses = Vec::new();
    if listen.is_empty() {
        addresses.push(SocketAddr::from(([127, 0, 0, 1], port)));
        addresses.extend(tailscale_ipv4().map(|ip| SocketAddr::new(ip, port)));
    }
    for value in listen {
        let address = value
            .parse::<SocketAddr>()
            .or_else(|_| value.parse::<IpAddr>().map(|ip| SocketAddr::new(ip, port)))
            .map_err(|_| format!("invalid listen address '{value}'"))?;
        if !is_allowed(address.ip()) {
            return Err(format!(
                "refusing to listen on {}: only loopback and Tailscale (100.64.0.0/10) addresses are allowed",
                address.ip()
            ));
        }
        addresses.push(address);
    }
    Ok(addresses)
}

/// Keeps one server thread per address and retries the ones that could not
/// bind yet (the Tailscale IP appears only once tailscaled is up).
pub fn serve_forever(paths: Paths, listen: Vec<String>, port: u16) -> Result<(), String> {
    resolve_addresses(&listen, port)?; // reject bad --listen values up front
    let mut running: HashMap<SocketAddr, JoinHandle<()>> = HashMap::new();
    loop {
        running.retain(|_, handle| !handle.is_finished());
        match resolve_addresses(&listen, port) {
            Ok(addresses) => {
                for address in addresses {
                    if running.contains_key(&address) {
                        continue;
                    }
                    match tiny_http::Server::http(address) {
                        Ok(server) => {
                            println!("web UI on http://{address}/");
                            let paths = paths.clone();
                            running.insert(
                                address,
                                std::thread::spawn(move || handle_requests(server, paths)),
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

fn handle_requests(server: tiny_http::Server, paths: Paths) {
    for request in server.incoming_requests() {
        let response = respond(&request, &paths);
        let _ = request.respond(response);
    }
}

type Response = tiny_http::Response<std::io::Cursor<Vec<u8>>>;

fn header(name: &str, value: &str) -> tiny_http::Header {
    tiny_http::Header::from_bytes(name.as_bytes(), value.as_bytes()).expect("valid header")
}

fn reply(status: u16, content_type: &str, body: String) -> Response {
    tiny_http::Response::from_string(body)
        .with_status_code(status)
        .with_header(header("Content-Type", content_type))
        .with_header(header("Cache-Control", "no-store"))
        .with_header(header("X-Content-Type-Options", "nosniff"))
        .with_header(header("X-Frame-Options", "DENY"))
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

/// Host must be an IP literal or localhost, so a web page that rebinds its
/// DNS name to 127.0.0.1 cannot read the data.
fn host_is_allowed(request: &tiny_http::Request) -> bool {
    let Some(host) = request.headers().iter().find(|h| h.field.equiv("Host")) else {
        return false;
    };
    let host = host.value.as_str();
    let name = if let Some(rest) = host.strip_prefix('[') {
        rest.split(']').next().unwrap_or("")
    } else {
        host.rsplit_once(':').map(|(name, _)| name).unwrap_or(host)
    };
    name.eq_ignore_ascii_case("localhost") || name.parse::<IpAddr>().is_ok()
}

fn respond(request: &tiny_http::Request, paths: &Paths) -> Response {
    if !host_is_allowed(request) {
        return reply(
            421,
            "text/plain",
            "Use the IP address or localhost.".to_string(),
        );
    }
    if *request.method() != tiny_http::Method::Get {
        return reply(405, "text/plain", "Read-only.".to_string());
    }
    let (path, query) = request.url().split_once('?').unwrap_or((request.url(), ""));
    let params = parse_query(query);
    match path {
        "/" => reply(200, "text/html; charset=utf-8", PAGE.to_string()),
        "/api/status" => json_reply(with_conn(paths, |conn| {
            let mut status = headless::status(conn, paths)?;
            status["last_run"] = crate::schedule::last_run(paths).unwrap_or(Value::Null);
            Ok(status)
        })),
        "/api/usage" => {
            let group_by = match params.get("by").map(|v| v.parse::<GroupBy>()).transpose() {
                Ok(group_by) => group_by.unwrap_or(GroupBy::Provider),
                Err(error) => {
                    return reply(
                        400,
                        "application/json",
                        serde_json::json!({ "error": error }).to_string(),
                    )
                }
            };
            json_reply(with_conn(paths, |conn| {
                let query = UsageQuery {
                    since: params.get("since").cloned(),
                    until: params.get("until").cloned(),
                    provider: params.get("provider").cloned(),
                    group_by,
                };
                Ok(Value::Array(headless::usage(conn, &query)?))
            }))
        }
        _ => reply(404, "text/plain", "Not found.".to_string()),
    }
}

fn with_conn(
    paths: &Paths,
    f: impl FnOnce(&headless::Connection) -> Result<Value, String>,
) -> Result<Value, String> {
    let conn = headless::open_connection(&paths.db)?;
    f(&conn)
}

/// Query values are dates, providers and group names: keep only safe characters.
fn parse_query(query: &str) -> HashMap<String, String> {
    query
        .split('&')
        .filter_map(|pair| pair.split_once('='))
        .map(|(key, value)| {
            let value = value.replace("%3A", ":").replace("%3a", ":");
            let value: String = value
                .chars()
                .filter(|c| {
                    c.is_ascii_alphanumeric() || matches!(c, '-' | ':' | '.' | '_' | 'T' | 'Z')
                })
                .collect();
            (key.to_string(), value)
        })
        .filter(|(_, value)| !value.is_empty())
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_loopback_and_tailscale_addresses_are_allowed() {
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
        assert!(resolve_addresses(&["0.0.0.0".to_string()], 8765).is_err());
        assert!(resolve_addresses(&["[::]:80".to_string()], 8765).is_err());
        assert_eq!(
            resolve_addresses(&["100.115.64.43".to_string()], 8765).unwrap(),
            vec!["100.115.64.43:8765".parse().unwrap()]
        );
    }

    #[test]
    fn query_values_keep_only_safe_characters() {
        let params = parse_query("by=day&since=2026-10-01T00%3A00%3A00Z&provider=<script>&x=");
        assert_eq!(params["by"], "day");
        assert_eq!(params["since"], "2026-10-01T00:00:00Z");
        assert_eq!(params["provider"], "script");
        assert!(!params.contains_key("x"));
    }
}
