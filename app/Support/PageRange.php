<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A page number past the end of a list ("?page=99" on a list of 23 rows) holds nothing, which a page would then describe as
 * "nothing here yet" while rows exist. Send the visitor to the last page that has rows, keeping every other query value.
 * A list that is genuinely empty is left alone so it can show its real empty state.
 */
final class PageRange
{
    /**
     * @template T of LengthAwarePaginator
     *
     * @param  T  $paginator
     * @return T
     *
     * @throws HttpResponseException a redirect to the last page when the requested page is beyond it
     */
    public static function within(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        if ($paginator->total() > 0 && $paginator->count() === 0 && $paginator->currentPage() > $paginator->lastPage()) {
            throw new HttpResponseException(redirect()->to($paginator->url($paginator->lastPage())));
        }

        return $paginator;
    }
}
