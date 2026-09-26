<?php

namespace App\Http\Controllers;

use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\SeriesMatch;
use App\Support\Search\PlayerMatches;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The site search behind the header's search field (P16), for guests too:
 * players by name, NIP-05 or npub (PlayerMatches, the player picker's
 * matching), clans by name or tag, and a match by its number. "#123" or
 * "123" of an existing Rocket League series or chess game opens it at once
 * (matches.show sends a chess number on to its game). Each group is capped
 * at LIMIT rows and carries only what the public pages show anyway.
 * Throttled per IP (`search`, AppServiceProvider). The page is noindex:
 * it never calls PageMeta::describe().
 */
class SearchController extends Controller
{
    public const LIMIT = 8;

    public function __invoke(Request $request): View|RedirectResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        $term = PlayerMatches::normalize((string) ($validated['q'] ?? ''));
        $number = preg_match('/^#?\s*(\d{1,9})$/', $term, $digits) === 1 ? (int) $digits[1] : null;

        if ($number !== null && (SeriesMatch::query()->where('number', $number)->exists() || ChessGame::query()->where('number', $number)->exists())) {
            return redirect()->route('matches.show', $number);
        }

        $players = PlayerMatches::query($term)?->limit(self::LIMIT)->get(['id', 'pubkey', 'npub', 'name', 'picture', 'avatar_path']) ?? collect();

        return view('pages.search', [
            'term' => $term,
            'number' => $number,
            'players' => $players,
            'clans' => $this->clans($term),
        ]);
    }

    /**
     * Clans by name (anywhere in it) or tag (from its start), names first.
     *
     * @return Collection<int, Clan>
     */
    private function clans(string $term): Collection
    {
        if (mb_strlen($term) < PlayerMatches::MIN_TERM) {
            return collect();
        }

        $like = PlayerMatches::escapeLike(mb_strtolower($term));

        return Clan::query()
            ->where(fn (Builder $query) => $query
                ->whereRaw("lower(name) like ? escape '!'", ['%'.$like.'%'])
                ->orWhereRaw("lower(clantag) like ? escape '!'", [$like.'%']))
            ->withCount('members')
            ->orderByRaw("case when lower(clantag) = ? then 0 when lower(name) like ? escape '!' then 1 else 2 end", [mb_strtolower($term), $like.'%'])
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();
    }
}
