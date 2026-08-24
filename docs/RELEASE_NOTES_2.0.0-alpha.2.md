# PenguLab 2.0.0-alpha.2

This pre-release focuses on a safer Proxmox test/update path and clean separation between stable and preview Docker channels.

## Highlights

- New interactive **Proxmox VE LXC installer**
- Stable, pre-release and exact-version install modes
- New `pengulabctl` management command inside Proxmox LXCs
- Automatic backup before image updates
- Separate GHCR channels:
  - `latest` = stable releases only
  - `prerelease` = latest alpha/beta/RC
  - exact release tag = reproducible/pinned install
- GitHub pre-releases no longer overwrite the stable `latest` image
- Version is now read centrally from the `VERSION` file

## Testing the pre-release on Proxmox

For testing, create a **separate LXC** and choose `Pre-release` in the installer. Do not replace the production PenguLab LXC with an alpha build.

Run on the Proxmox host:

```bash
curl -fsSL https://raw.githubusercontent.com/Borderlane-HA/PenguLab/main/scripts/proxmox-lxc-install.sh -o /tmp/pengulab-lxc-install.sh
bash /tmp/pengulab-lxc-install.sh
```

Select:

```text
2) Pre-release - alpha/beta testing (Docker tag: prerelease)
```

Inside the test LXC, future alpha updates are then simply:

```bash
pengulabctl update
```

To pin this exact build:

```bash
pengulabctl version 2.0.0-alpha.2
pengulabctl update
```

## Important note for alpha.1

The `2.0.0-alpha.1` workflow still applied the `latest` Docker tag to every published GitHub release, including pre-releases. If alpha.1 was already published through that workflow, `latest` may temporarily point to alpha.1.

After merging the alpha.2 workflow, use **Actions → Publish Docker image → Run workflow** once with the last stable source tag and the `stable` channel to restore `latest` to the stable build. For example:

```text
source_ref: 1.0.3
image_tag: 1.0.3
channel: stable
```

Future pre-releases automatically update only `prerelease`, never `latest`.
