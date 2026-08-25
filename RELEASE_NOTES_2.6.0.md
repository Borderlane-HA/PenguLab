# PenguLab 2.6.0

PenguLab 2.6.0 focuses on a more flexible dashboard and a substantially richer OPNsense widget.

## Dashboard

- The vertical dashboard grid is now **4× finer**. Existing layouts are migrated automatically so they initially keep the same visual size and position.
- Desktop and iPad resizing now has many more useful intermediate height steps.
- Portable exports from older 2.x versions are converted to the new grid during import.

## OPNsense

The OPNsense integration can now optionally display:

- CPU, RAM, system disk, temperature and uptime
- firewall state usage
- gateway latency, packet loss and RTT deviation
- automatically discovered/selectable gateway for Multi-WAN setups
- interface traffic plus errors/drops
- WireGuard status, peers and RX/TX per peer
- OPNsense services
- CARP/VIP state

The existing compact defaults remain intentionally small; additional sections can be enabled under **Integration bearbeiten → Widget-Inhalte**.

## AdGuard Home

- Fixed the empty metric column when no client count is available.
- Three metrics now use the complete width of the widget.
- Metric cards no longer stretch vertically just because the widget is taller; controls stay anchored at the bottom.

## TLS default

New built-in integrations now start with **Verify TLS certificate disabled**, which is friendlier for homelabs using self-signed certificates. Existing integrations keep their current setting. Enable verification when the service uses a certificate trusted by the PenguLab container.

## Docker image

```text
ghcr.io/borderlane-ha/pengulab:2.6.0
```
