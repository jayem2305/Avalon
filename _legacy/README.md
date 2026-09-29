# Avalon

Online multiplayer *The Resistance: Avalon* for 5–10 players. Each player joins a room from their own phone or computer, and the server deals the secret roles and enforces the rules.

## Run it

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open <http://localhost/avalon/>.

The `avalon` database and its table are created automatically. Connection settings are in `config.php`.

To play with people on your Wi-Fi, share `http://<your-PC's-IP>/avalon/` (find the IP with `ipconfig`). You may need to allow Apache through Windows Firewall. Players outside your network need the site hosted publicly, or a tunnel such as ngrok or Cloudflare Tunnel.

No MySQL? Set `DB_DRIVER` to `'sqlite'` in `config.php` and the data is stored in `data/avalon.sqlite` instead.

## Features

- Room codes and invite links, with a lobby where the host picks the roles
- Merlin, Assassin, Percival, Morgana, Mordred, Oberon and Lady of the Lake
- Correct team sizes, the two-fail 4th quest at 7+ players, and the 5-rejection loss
- Secret votes and quest cards. Votes are revealed once everyone has voted; for quest cards, only the number of Fails is shown
- Each player sees exactly what their role allows, and the server never sends other players' roles until the game ends
- Assassination phase, full role reveal, and Play again
- Chat, game log and a vote history

## Layout

| File | Purpose |
|---|---|
| `lib/game.php` | Rules engine: every action and what each player may see |
| `lib/db.php` | Storage. One row per room, updated inside a locked transaction |
| `api.php` | JSON API used by the page |
| `assets/app.js` | Client: screens, polling (every 1.5 s) and actions |
| `assets/style.css` | Styles |

Your seat is remembered in the browser tab. To test several players on one computer, use separate browser profiles or private windows, or add `?fresh` to the URL.
