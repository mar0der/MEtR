import { check } from "@tauri-apps/plugin-updater";
import { ask, message } from "@tauri-apps/plugin-dialog";
import { relaunch } from "@tauri-apps/plugin-process";

export async function checkForUpdates(onUserClick = false) {
  try {
    const update = await check();

    if (!update) {
      if (onUserClick) {
        await message("Failed to check for updates. Please try again later.", {
          title: "Update Check Failed",
          kind: "error",
          okLabel: "OK",
        });
      }
      return;
    }

    if (!update.available) {
      if (onUserClick) {
        await message("You are on the latest version of MEtR.", {
          title: "Up to Date",
          kind: "info",
          okLabel: "OK",
        });
      }
      return;
    }

    const bodyText = update.body?.trim() ? `\n\nRelease notes:\n${update.body}` : "";
    const yes = await ask(
      `MEtR ${update.version} is available!${bodyText}\n\nDownload and install now?`,
      {
        title: "Update Available",
        kind: "info",
        okLabel: "Update",
        cancelLabel: "Later",
      }
    );

    if (yes) {
      let downloaded = 0;
      let contentLength = 0;

      await update.downloadAndInstall((event) => {
        switch (event.event) {
          case "Started":
            contentLength = event.data.contentLength ?? 0;
            console.log(`Download started. Total size: ${contentLength}`);
            break;
          case "Progress":
            downloaded += event.data.chunkLength;
            if (contentLength > 0) {
              const percent = Math.round((downloaded / contentLength) * 100);
              console.log(`Download progress: ${percent}%`);
            }
            break;
          case "Finished":
            console.log("Download finished");
            break;
        }
      });

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
        await relaunch();
      }
    }
  } catch (err) {
    console.error("Update check failed:", err);
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
