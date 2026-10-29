//! `metr self-update`: build the newest tagged release from source.
//! Downloads the release source into a fresh private folder under
//! ~/.cache/metr, builds with cargo at low priority, swaps the binary
//! atomically, and deletes the folder.

use std::path::{Path, PathBuf};
use std::process::Command;

const TAGS_URL: &str = "https://api.github.com/repos/mar0der/MEtR/tags?per_page=100";
const SOURCE_URL: &str = "https://codeload.github.com/mar0der/MEtR/tar.gz/refs/tags";

fn parse_version(text: &str) -> Option<(u64, u64, u64)> {
    let mut parts = text
        .trim_start_matches('v')
        .split('.')
        .map(|p| p.parse::<u64>().ok());
    Some((parts.next()??, parts.next()??, parts.next()??))
}

fn latest_tag(client: &reqwest::blocking::Client) -> Result<(String, (u64, u64, u64)), String> {
    let tags: Vec<serde_json::Value> = client
        .get(TAGS_URL)
        .send()
        .and_then(|r| r.error_for_status())
        .and_then(|r| r.json())
        .map_err(|e| format!("cannot list releases: {e}"))?;
    tags.iter()
        .filter_map(|t| t["name"].as_str())
        .filter(|name| name.starts_with('v'))
        .filter_map(|name| parse_version(name).map(|v| (name.to_string(), v)))
        .max_by_key(|(_, v)| *v)
        .ok_or_else(|| "no release tags found".to_string())
}

fn run(command: &mut Command, what: &str) -> Result<(), String> {
    let status = command.status().map_err(|e| format!("{what}: {e}"))?;
    if status.success() {
        Ok(())
    } else {
        Err(format!("{what} failed ({status})"))
    }
}

pub fn self_update(force: bool, restart: Option<&str>, system: bool) -> Result<(), String> {
    let current = parse_version(metr_lib::VERSION).ok_or("bad own version")?;
    let client = reqwest::blocking::Client::builder()
        .user_agent(format!("metr-cli/{}", metr_lib::VERSION))
        .build()
        .map_err(|e| e.to_string())?;
    let (tag, latest) = latest_tag(&client)?;
    if latest <= current && !force {
        println!("Up to date ({}).", metr_lib::VERSION);
        return Ok(());
    }
    if Command::new("cargo").arg("--version").output().is_err() {
        return Err("cargo is needed to build the update (install Rust with rustup)".to_string());
    }
    let exe = std::env::current_exe()
        .and_then(|p| p.canonicalize())
        .map_err(|e| format!("cannot find the metr binary: {e}"))?;
    let work = private_work_dir()?;
    let result = build_and_install(&client, &tag, &work, &exe);
    let _ = std::fs::remove_dir_all(&work);
    result?;
    println!("Updated to {tag} ({}).", exe.display());
    if let Some(unit) = restart {
        let mut command = Command::new("systemctl");
        if !system {
            command.arg("--user");
        }
        run(command.args(["restart", unit]), "systemctl restart")?;
        println!("Restarted {unit}.");
    }
    Ok(())
}

/// A new folder only this user can enter. Not /tmp: a predictable path there
/// lets another user swap the build output, and builds can fill a tmpfs.
fn private_work_dir() -> Result<PathBuf, String> {
    let cache = std::env::var_os("XDG_CACHE_HOME")
        .map(PathBuf::from)
        .filter(|p| p.is_absolute())
        .or_else(|| std::env::var_os("HOME").map(|h| PathBuf::from(h).join(".cache")))
        .ok_or("HOME is not set")?
        .join("metr");
    std::fs::create_dir_all(&cache).map_err(|e| format!("{}: {e}", cache.display()))?;
    let nonce = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_nanos())
        .unwrap_or_default();
    let work = cache.join(format!("update-{}-{nonce}", std::process::id()));
    let mut builder = std::fs::DirBuilder::new();
    #[cfg(unix)]
    {
        use std::os::unix::fs::DirBuilderExt;
        builder.mode(0o700);
    }
    // create, not create_all: fails if the folder already exists.
    builder
        .create(&work)
        .map_err(|e| format!("{}: {e}", work.display()))?;
    Ok(work)
}

fn build_and_install(
    client: &reqwest::blocking::Client,
    tag: &str,
    work: &Path,
    exe: &Path,
) -> Result<(), String> {
    let archive = work.join("source.tar.gz");
    println!("Downloading {tag}...");
    let bytes = client
        .get(format!("{SOURCE_URL}/{tag}"))
        .send()
        .and_then(|r| r.error_for_status())
        .and_then(|r| r.bytes())
        .map_err(|e| format!("download failed: {e}"))?;
    std::fs::write(&archive, &bytes).map_err(|e| e.to_string())?;
    let source = work.join("source");
    std::fs::create_dir_all(&source).map_err(|e| e.to_string())?;
    run(
        Command::new("tar")
            .arg("-xzf")
            .arg(&archive)
            .arg("-C")
            .arg(&source)
            .arg("--strip-components=1"),
        "unpacking the source",
    )?;
    let manifest = source.join("src-tauri/Cargo.toml");
    if !source.join("src-tauri/cli/Cargo.toml").is_file() {
        return Err(format!("release {tag} does not contain the CLI"));
    }
    println!("Building (low priority, a few minutes)...");
    run(
        Command::new("nice")
            .args([
                "-n",
                "19",
                "cargo",
                "build",
                "--release",
                "--locked",
                "-p",
                "metr-cli",
                "--manifest-path",
            ])
            .arg(&manifest)
            .env("CARGO_TARGET_DIR", work.join("target")),
        "cargo build",
    )?;
    let built: PathBuf = work.join("target/release/metr-cli");
    run(
        Command::new(&built).arg("--version"),
        "checking the new binary",
    )?;
    // Copy next to the old binary, then rename over it (atomic on one file system).
    let staged = exe.with_extension("new");
    std::fs::copy(&built, &staged).map_err(|e| format!("{}: {e}", staged.display()))?;
    std::fs::rename(&staged, exe).map_err(|e| format!("{}: {e}", exe.display()))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn versions_compare_numerically() {
        assert!(parse_version("v26.41.0") > parse_version("v26.9.9"));
        assert_eq!(parse_version("26.41.0"), Some((26, 41, 0)));
        assert_eq!(parse_version("v26.41"), None);
    }
}
