<?php

namespace App\Services\Site;

/**
 * Pulls the readable part out of one of the literature files.
 *
 * Those 105 files are complete HTML documents — doctype, `<head>`, a `<style>`
 * block, `<body>` — scanned and cleaned up years ago, and they are the
 * content. The Next.js site injected each whole file into an `<article>` with
 * `dangerouslySetInnerHTML`, which works because a browser parsing a document
 * string in element context throws away `<!DOCTYPE>`, `<html>`, `<head>` and
 * `<body>` while keeping their children. So the `<style>` survived and was
 * applied; the `<meta>` tags became no-ops.
 *
 * This reproduces **that** result rather than a tidier one: the body's
 * contents, with any `<style>` from the head kept in front of it. A page whose
 * links are currently dark red goes on being dark red, and nobody has to
 * review 105 files to find out which ones cared.
 */
class HtmlDocument
{
    public static function readable(string $html): string
    {
        $style = '';

        if (preg_match_all('#<style\b[^>]*>.*?</style>#is', $html, $matches) !== false) {
            $style = implode("\n", $matches[0] ?? []);
        }

        $body = preg_match('#<body\b[^>]*>(.*)</body>#is', $html, $m) === 1
            ? $m[1]
            // No <body> at all: a fragment, which some of the files may yet
            // become if they are ever regenerated. Use it as it stands, minus
            // anything that cannot appear inside an article.
            : preg_replace('#</?(?:!DOCTYPE|html|head|body)\b[^>]*>#i', '', $html);

        // The style blocks are re-attached in front, so a <style> that lived in
        // the head still applies — and is not duplicated out of the body.
        $body = preg_replace('#<style\b[^>]*>.*?</style>#is', '', (string) $body);

        return trim($style."\n".$body);
    }
}
