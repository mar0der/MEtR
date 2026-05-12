import { check } from "@tauri-apps/plugin-updater";
import { ask, message } from "@tauri-apps/plugin-dialog";
import { relaunch } from "@tauri-apps/plugin-process";
import { getVersion } from "@tauri-apps/api/app";

const COOLDOWN_MS = 60 * 60 * 1000; // 1 hour
const STORAGE_KEY_LAST_ATTEMPT = "metr_update_last_attempt";
const STORAGE_KEY_SKIPPED_VERSION = "metr_update_skipped_version";
const STORAGE_KEY_JUST_RESTARTED = "metr_update_just_restarted";

interface AttemptRecord {
  version: string;
  timestamp: number;
  installed: boolean;
}

function getLastAttempt(): AttemptRecord | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY_LAST_ATTEMPT);
    return raw ? (JSON.parse(raw) as AttemptRecord) : null;
  } catch {
    return null;
  }
}

function setLastAttempt(version: string, installed: boolean) {
  const record: AttemptRecord = { version, timestamp: Date.now(), installed };
  localStorage.setItem(STORAGE_KEY_LAST_ATTEMPT, JSON.stringify(record));
}

function getSkippedVersion(): string | null {
  return localStorage.getItem(STORAGE_KEY_SKIPPED_VERSION);
}

function setSkippedVersion(version: string) {
  localStorage.setItem(STORAGE_KEY_SKIPPED_VERSION, version);
}

function clearSkippedVersion() {
  localStorage.removeItem(STORAGE_KEY_SKIPPED_VERSION);
}

function wasJustRestartedAfterUpdate(): boolean {
  return localStorage.getItem(STORAGE_KEY_JUST_RESTARTED) === "1";
}

function markRestartingForUpdate() {
  localStorage.setItem(STORAGE_KEY_JUST_RESTARTED, "1");
}

function clearJustRestarted() {
  localStorage.removeItem(STORAGE_KEY_JUST_RESTARTED);
}

export async function checkForUpdates(onUserClick = false) {
  // If we just restarted after an update, clear the flag and log the current version.
  // This helps diagnose whether the update actually applied.
  if (!onUserClick && wasJustRestartedAfterUpdate()) {
    clearJustRestarted();
    const currentVersion = await getVersion();
    console.log("[Updater] App restarted after update. Current version:", currentVersion);
    const lastAttempt = getLastAttempt();
    if (lastAttempt && lastAttempt.installed) {
      // If we attempted to install this version but we're still not on it,
      // the bundle replacement failed. Skip auto-check for cooldown period.
      if (lastAttempt.version !== currentVersion) {
        console.warn(
          `[Updater] Bundle replacement may have failed. Expected ${lastAttempt.version}, got ${currentVersion}. Skipping auto-check for cooldown.`
        );
        // Don't return entirely — let the check run but we won't prompt if same version
      }
    }
  }

  // Auto-check cooldown: if we already attempted an update recently, skip.
  if (!onUserClick) {
    const lastAttempt = getLastAttempt();
    if (lastAttempt) {
      const elapsed = Date.now() - lastAttempt.timestamp;
      if (elapsed < COOLDOWN_MS) {
        console.log(
          `[Updater] Skipping auto-check — last attempt was ${Math.round(elapsed / 1000)}s ago (cooldown: ${COOLDOWN_MS / 1000}s)`
        );
        return;
      }
    }
  }

  try {
    console.log("[Updater] Checking for updates...");
    const currentVersion = await getVersion();
    console.log("[Updater] Current version:", currentVersion);

    const update = await check();

    // Tauri returns null when server responds with 204 No Content (no update available)
    if (update === null) {
      console.log("[Updater] No update available (server returned 204).");
      if (onUserClick) {
        await message(`You are on the latest version of MEtR (${currentVersion}).`, {
          title: "Up to Date",
          kind: "info",
          okLabel: "OK",
        });
      }
      return;
    }

    console.log("[Updater] Server response — available:", update.available, "version:", update.version);

    if (!update.available) {
      if (onUserClick) {
        await message(`You are on the latest version of MEtR (${currentVersion}).`, {
          title: "Up to Date",
          kind: "info",
          okLabel: "OK",
        });
      }
      return;
    }

    // Skip check: if user previously skipped this version, don't prompt again on auto-check.
    if (!onUserClick) {
      const skipped = getSkippedVersion();
      if (skipped && skipped === update.version) {
        console.log(`[Updater] Skipping auto-prompt for version ${update.version} (user skipped).`);
        return;
      }
    }

    const bodyText = update.body?.trim() ? `\n\nRelease notes:\n${update.body}` : "";
    const yes = await ask(
      `MEtR ${update.version} is available! (You have ${currentVersion})${bodyText}\n\nDownload and install now?`,
      {
        title: "Update Available",
        kind: "info",
        okLabel: "Update",
        cancelLabel: "Later",
      }
    );

    if (yes) {
      clearSkippedVersion();
      let downloaded = 0;
      let contentLength = 0;

      await update.downloadAndInstall((event) => {
        switch (event.event) {
          case "Started":
            contentLength = event.data.contentLength ?? 0;
            console.log(`[Updater] Download started. Total size: ${contentLength}`);
            break;
          case "Progress":
            downloaded += event.data.chunkLength;
            if (contentLength > 0) {
              const percent = Math.round((downloaded / contentLength) * 100);
              console.log(`[Updater] Download progress: ${percent}%`);
            }
            break;
          case "Finished":
            console.log("[Updater] Download finished");
            break;
        }
      });

      console.log("[Updater] Install complete. Prompting for restart.");
      setLastAttempt(update.version, true);
      markRestartingForUpdate();

      const restartNow = await ask(
        "Update installed successfully. Restart MEtR now?",
        {
          title: "Restart Required",
          kind: "info",
          okLabel: "Restart Now",
          cancelLabel: "Later",
        }
      );

      if (restartNow) {
        console.log("[Updater] Relaunching...");
        await relaunch();
      }
    } else if (onUserClick) {
      // User explicitly declined this version — offer to skip it.
      const skip = await ask(
        `Skip MEtR ${update.version} and don't remind again?`,
        {
          title: "Skip Version",
          kind: "info",
          okLabel: "Skip",
          cancelLabel: "No",
        }
      );
      if (skip) {
        setSkippedVersion(update.version);
      }
      setLastAttempt(update.version, false);
    }
  } catch (err) {
    console.error("[Updater] Update check failed:", err);
    if (onUserClick) {
      await message(
        "Could not check for updates.\n\n" +
          (err instanceof Error ? err.message : String(err)),
        {
          title: "Update Error",
          kind: "error",
          okLabel: "OK",
        }
      );
    }
  }
}
