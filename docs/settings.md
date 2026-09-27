# Settings

What each setting does, for a player and for an administrator.

Personal settings → Arcade:

| Setting | Default | Description |
| --- | --- | --- |
| Smooth video filtering | Off | Bilinear filtering instead of sharp pixels |
| Pixel-perfect scaling | Off | Scale by whole pixels, with borders |
| Capture input globally | On | Send gamepad and keyboard input to the game while playing |
| Pause in the background | On | Stop the game while its tab is hidden |
| Save when closing | On | Write the Auto save state when the player is closed |
| Continue on start | Off | Load the latest save as a game starts, without asking |
| Save every | Never | Write the Auto save state while playing, from 30 seconds to 10 minutes |
| Rewind | Off | Go back through the game while the rewind key is held; costs some performance |
| Run-ahead | 0 frames | Hide input lag, up to 3 frames, at the cost of CPU |
| Fast-forward speed | 3× | Speed of the fast-forward button, 1× to 5× |
| Volume | 0 dB | Gain in decibels, -20 to 10 |
| Audio latency | 64 ms | Raise it if the sound crackles |
| Games library folder | `/Games` | Scanned for the games library page |
| Saves folder | empty | Save states and battery saves, in your own files. Without one, a game cannot be saved |
| Screenshots folder | empty | Where the screenshot button saves images |
| Thumbnails folder | empty | Images used as game thumbnails |
| System folder | empty | Where BIOS files are read from |
| Picture shown for each system | Box art | Box art, title screen, screenshot or logo, per system |
| Controls | see above | The key of every button and of the player itself |

There is no Save button anywhere: every setting is saved the moment it is
changed, and the section says so beside its heading.

Folder settings have a browse button that opens the NextCloud file picker.
The thumbnails folder also has a button that goes looking for the box art of
the games that have none; it runs as a background job, so it carries on
after the page is closed, says how it went when the page is opened
again, and leaves a notification under the bell when it is done.

Administration settings → Arcade holds what is the same for everybody:

| Setting | Default | Description |
| --- | --- | --- |
| Folder defaults | as above | What new users start with, and each can still change |
| Look up box art | On | Whether the server may ask the libretro thumbnail server at all |
| Work out ROM checksums | Off | Whether to read a whole ROM to hash it, when the upload brought no checksum |
| Games listed at most | 5000 | How many games one library scan lists |
| Folders deep at most | 6 | How far into a library folder the scan goes |
| Seconds a scan is kept | 86400 | How long the result of a scan is cached |
| Core options | Core default | Options of the emulator cores, which users cannot change |
| Picture each system starts out shown with | Box art | The default for the personal setting of the same name |

Box art, checksums and the three scan limits are scalars of the instance,
so they live in a declarative settings form that the server renders and
saves itself; the rest of the page is the app's own markup. Everything on
it saves as it is changed, like the personal page. The administration
page also holds a [BIOS section](bios.md), once a System folder is set.

An option of a core belongs to the core rather than to whoever is playing,
so those are the administrator's alone. Looking up box art is the only thing
in the app that has the server itself fetch from the internet, which is why
it can be turned off for the instance: with it off, the button is gone from
the personal settings and the endpoint refuses. The kind of picture a system
is shown with is a matter of taste, so the administration page only sets
where everybody starts.

Box art, title screen, screenshot and logo are the `Named_*` folders a
picture is taken from, so a system shown with title screens is matched
against `Named_Titles` rather than `Named_Boxarts`.

## Where saves are kept

With a saves folder set, save states are written to your own files as
`Saves/Mario/Slot 1.state` with `Slot 1.png` next to it, the automatic one
as `Saves/Mario/Auto.state`, and the battery save as `Saves/Mario/Mario.srm`,
so they sync to your devices. Left empty, they
are kept in the app's internal storage instead. Switching the setting does
not move existing saves.
