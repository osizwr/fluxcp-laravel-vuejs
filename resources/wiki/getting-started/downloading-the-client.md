---
title: Downloading the client
summary: Where the game files come from, how to install them, and what to do when the launcher will not start.
order: 3
---

The game is a program you download once. After that it patches itself, so you
only ever do this again if you move to a new machine.

## Getting the files

The [downloads page](/downloads) lists every package this server publishes and
every mirror for it. The mirrors carry the same file, so pick whichever is
fastest where you are.

## Installing

1. **Unpack it into a folder of its own.** Not into your Downloads folder, and
   not on top of another server's client — two Ragnarok installs sharing a
   folder will overwrite each other's data files, and the symptom is a client
   that crashes for no visible reason.
2. **Keep it out of Program Files.** On Windows, that folder is write-protected
   in a way the patcher does not expect, and the first patch will fail.
3. **Run the launcher, not the game executable.** The launcher fetches patches
   before it hands over to the game. Starting the game directly skips that and
   you will be connecting with the wrong files.

## When it will not start

- **The patcher hangs or fails.** Usually an antivirus holding the files it is
  trying to replace. Allow the game's folder, then run the launcher again.
- **It says the client is out of date.** The patcher did not finish. Run it
  again and let it get to the end before pressing play.
- **It crashes as soon as it opens.** Almost always an extracted-over-the-top
  install. Delete the folder and unpack a clean copy into an empty one.
- **It opens but will not sign in.** That is an account or a server problem
  rather than a client one — check that the server is up on the front page,
  and that your account is confirmed.

## Playing on a phone

If this server publishes an Android or iOS build, it is on the same downloads
page. Both are installed from the file rather than from a store, so each
platform asks you to allow that once: Android asks at install time, and iOS
asks you to trust the developer under Settings, General, VPN & Device
Management.
