<?php

namespace App\Services\Apple;

use Illuminate\Http\Request;

/**
 * The parameters `getlist.php` actually reads, and the two it does not.
 *
 * `getlist.php:13-20` pulls eight fields off the POST body. **`limit` and
 * `offset` are among them and are never used** — not in any of the nine
 * branches. They are read, assigned, and dropped. The client sends them; the
 * server has never paged. Worth knowing before somebody treats the absence of
 * paging as a regression: there is nothing to regress.
 *
 * `userid` is read too and goes unused in this script.
 */
final readonly class AppleListRequest
{
    /**
     * @param  array<string, string>  $filters  Sponsor-directory facets, present only when sent.
     */
    public function __construct(
        public int $accountId,
        public int $sponsorId,
        public bool $isSponsor,
        public ?int $step,
        public array $filters,
    ) {}

    public static function from(Request $request): self
    {
        return new self(
            accountId: (int) $request->input('accountid', 0),
            sponsorId: (int) $request->input('sponsorid', 0),
            // `$isSponsor == 1` in the original, a loose comparison against a
            // POST string. Anything that is not the number 1 is false, which is
            // what the loose compare did for every value the client sends.
            isSponsor: (string) $request->input('issponsor', '0') === '1',
            step: $request->has('step') && is_numeric($request->input('step'))
                ? (int) $request->input('step')
                : null,
            filters: self::filters($request),
        );
    }

    /**
     * `isset($_POST['country'])`, not "is it non-empty".
     *
     * A cleared filter form posts the field with an empty value, and the old
     * code filtered on the empty string, returning nothing. That is the
     * behaviour the client's "no results" state was built against, so
     * presence — not truthiness — is the test here too.
     *
     * @return array<string, string>
     */
    private static function filters(Request $request): array
    {
        $filters = [];
        foreach (['country', 'gender', 'age'] as $field) {
            if ($request->has($field)) {
                $filters[$field] = (string) $request->input($field);
            }
        }

        return $filters;
    }
}
