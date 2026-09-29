<?php

namespace App\Http\Controllers;

use App\Game\Avalon;
use App\Game\GameError;
use App\Services\RoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A player's seat is identified by a secret token kept in their Laravel
 * session (one per room code), so nothing sensitive lives in the browser.
 */
class RoomController extends Controller
{
    public function __construct(private RoomService $rooms) {}

    public function home(Request $request): Response
    {
        $resume = null;
        foreach (array_reverse($request->session()->get('avalon.tokens', []), true) as $code => $token) {
            try {
                if (Avalon::playerByToken($this->rooms->state($code), $token)) {
                    $resume = $code;
                    break;
                }
            } catch (GameError) {
                // Room is gone: nothing to resume.
            }
        }

        return Inertia::render('Home', [
            'code' => strtoupper((string) $request->query('room', '')),
            'resume' => $resume,
        ]);
    }

    public function create(Request $request): RedirectResponse
    {
        try {
            [$room, $host] = $this->rooms->create($request->input('name'));
        } catch (GameError $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }
        $this->remember($request, $room->code, $host['token']);

        return redirect()->route('room.show', $room->code);
    }

    public function join(Request $request): RedirectResponse
    {
        try {
            $code = Avalon::cleanCode($request->input('code'));
            [$player] = $this->rooms->mutate($code, fn (array &$s) => Avalon::join(
                $s, $request->input('name'), $this->token($request, $code)
            ));
        } catch (GameError $e) {
            $field = str_contains($e->getMessage(), 'name') ? 'name' : 'code';
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
        $this->remember($request, $code, $player['token']);

        return redirect()->route('room.show', $code);
    }

    public function show(Request $request, string $code): Response|RedirectResponse
    {
        try {
            $s = $this->rooms->state(Avalon::cleanCode($code));
            $me = Avalon::requirePlayer($s, $this->token($request, $s['code']));
        } catch (GameError) {
            // Not seated here (yet): send them to the join form with the code filled in.
            return redirect()->route('home', ['room' => strtoupper($code)]);
        }

        return Inertia::render('Room', ['initial' => Avalon::view($s, $me)]);
    }

    public function state(Request $request, string $code): JsonResponse
    {
        $s = $this->rooms->state(Avalon::cleanCode($code));
        $me = Avalon::requirePlayer($s, $this->token($request, $s['code']));
        if ((int) $request->query('v', -1) === $s['version']) {
            return response()->json(['unchanged' => true]);
        }

        return response()->json(['state' => Avalon::view($s, $me)]);
    }

    public function act(Request $request, string $code, string $action): JsonResponse
    {
        $code = Avalon::cleanCode($code);
        $token = $this->token($request, $code);
        if ($request->has('hostRole') && ! app()->isLocal()) {
            throw new GameError('Choosing your role is only available in local test mode.');
        }

        [$me, $s] = $this->rooms->mutate($code, function (array &$s) use ($request, $action, $token) {
            $me = Avalon::requirePlayer($s, $token);
            Avalon::apply($s, $me, $action, $request->all());

            return $me;
        });

        if (Avalon::findPlayer($s, $me['id']) === null) {
            $request->session()->forget("avalon.tokens.$code");

            return response()->json(['left' => true]);
        }

        return response()->json(['state' => Avalon::view($s, $me)]);
    }

    private function token(Request $request, string $code): ?string
    {
        return $request->session()->get("avalon.tokens.$code");
    }

    private function remember(Request $request, string $code, string $token): void
    {
        $request->session()->put("avalon.tokens.$code", $token);
    }
}
