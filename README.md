# Avalon

Online multiplayer *The Resistance: Avalon* for 5–10 players. Everyone joins a room from their own phone or computer. The server deals the secret roles, enforces the rules, and pushes every change to all players instantly over websockets.

**Stack:** Laravel 12 · Inertia v3 + Vue 3 · PostgreSQL (Supabase) or SQLite · Laravel Reverb · Vite

## Features

- **Rooms:** 4-letter room codes and invite links. The host picks the roles in the lobby: Merlin, the Assassin, Percival, Morgana, Mordred, Oberon and the Lady of the Lake.
- **Full rules:** team sizes for every player count, the two-fail 4th quest with 7 or more players, the 5-rejection loss, the Lady of the Lake, and the final assassination.
- **Bots:** fill empty seats so you can play or test on your own.
- **Test mode (local only):** the host can choose their own role for the next game.
- **Role cards:** each role has original art, shown on a card you tap to flip over.
- **Pixel-art Camelot:** every player is a knight walking around the Round Table. Quest teams march to the gate, and votes appear as speech bubbles. At the end, everyone changes into their role's costume.
- **The Assassin's hunt:** the page turns blood-red, night falls on the map, and a heartbeat plays until the strike.
- **Sound effects:** generated in the browser, with a mute button.
- **Layout:** fits one screen on desktops, and stacks on phones.
- **Play again:** instantly deals a new game at the same table.
- **Chat, game log,** and a vote history shown at the end of each game.

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan reverb:install      # creates your own Reverb app id/key/secret in .env
php artisan migrate
```

The app runs on SQLite out of the box. To use PostgreSQL or Supabase, fill in the `DB_*` block in `.env`, then run `php artisan migrate` again. For Supabase, use the **Session pooler** connection (port 5432, user `postgres.<project-ref>`). The direct `db.<ref>.supabase.co` host only works over IPv6.

## Run it

```bash
composer run dev       # web server :8000 + Reverb :8080 + Vite
```

Open <http://localhost:8000>. To try several players on one computer, use separate browser profiles or private windows, since each browser session holds one seat. Alternatively, add bots.

### Playing with friends on your Wi-Fi

```bash
composer run play
```

This builds the frontend and serves on all network interfaces. Players open `http://<your-PC's-IP>:8000` (find the IP with `ipconfig`). Windows Firewall must allow ports 8000 and 8080. Players outside your network need the app deployed somewhere public.

## How it works

| Piece | Where |
|---|---|
| Rules engine and bots: every action, plus what each player may see | `app/Game/Avalon.php` |
| Load / lock / save a room, then broadcast | `app/Services/RoomService.php` |
| HTTP endpoints (Inertia pages + JSON actions) | `app/Http/Controllers/RoomController.php`, `routes/web.php` |
| Live update event | `app/Events/RoomUpdated.php` |
| Vue pages and components | `resources/js/Pages`, `resources/js/Components` |
| Room state, actions, sounds, websocket listening | `resources/js/composables/useRoom.js` |
| Pixel-art map, knights and costumes | `resources/js/Components/PixelMap.vue` |

- **One JSON document per room** (`rooms.state`, `jsonb` on Postgres), changed only inside a transaction with the row locked, so simultaneous votes can't clash. A copy is cached locally so frequent reloads don't wait on a remote database.
- **Secrets stay on the server.** Reverb broadcasts only `{version}` on the public `room.{CODE}` channel. Each browser then fetches its own filtered view over HTTP, so roles, votes and quest cards never travel over the websocket, and other players' roles are only sent at game end.
- **Seats live in the Laravel session**, as one secret token per room code. Reloading the page or reopening the invite link takes you back to your seat.
- **Resilient:** if Reverb is down, actions still work and clients fall back to polling.

## Tests

```bash
php artisan test
```

The suite plays full games at 5, 7, 8 and 10 players with every role, and whole games with one human and six bots. It also checks each role's knowledge, random dealing, test mode, Play again, the two-fail 4th quest, the 5-rejection loss and the Lady of the Lake. For the HTTP flow, it covers sessions, errors, broadcasting, caching, and that no roles or tokens leak.

## Old version

The original plain-PHP/MySQL version is kept in `_legacy/`. The root `.htaccess` stops XAMPP's Apache from serving anything else in this folder, such as `.env`.
