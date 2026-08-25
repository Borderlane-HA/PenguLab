# PenguLab 2.6.1

PenguLab 2.6.1 refines dashboard sizing and fixes navigation after editing integrations directly from a widget.

## Dashboard grid

- Desktop and tablet layouts now use **24 columns instead of 12**.
- Existing widget positions and widths are migrated automatically and keep the same visual size.
- The extra columns provide useful intermediate widths for compact Home Assistant, app and service widgets.
- The 2.6 fine vertical grid remains unchanged.

## Integration editing

- Saving an integration from a dashboard widget now returns to the view that opened the dialog.
- Editing from the Integrations page continues to return to the Integrations page.
- This also fixes the stale state where clicking Dashboard could appear to leave the Integrations overview visible until a reload.

## Logout

- The top-bar logout action is now icon-only.
- Clicking it asks for confirmation before signing out.

## Docker image

```text
ghcr.io/borderlane-ha/pengulab:2.6.1
```
