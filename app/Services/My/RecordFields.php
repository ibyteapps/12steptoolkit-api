<?php

namespace App\Services\My;

use App\Models\Night;

/**
 * What each record type is made of, as fields a form can render and a
 * controller can whitelist.
 *
 * The wording is the app's own, lifted from the web client's `arrays.js` and
 * `step-work.js` rather than rewritten, so a member sees the same questions in
 * the browser as on their phone. Two notes on that:
 *
 *  * **The nightly questions map to `desc1..desc12` and `sw1..sw12`**, and
 *    question eight has no switch — it asks what could have been done better,
 *    which has no yes or no. That is why `Night::SWITCHES` skips `sw8`.
 *  * **"Were were resentful?"** is a typo in the web client. The phones ask
 *    "Were we resentful?" and so does this.
 *
 * A column absent from here cannot be written, whatever a form posts, because
 * the controller builds its update from this list and not from the request.
 */
final class RecordFields
{
    public const AFFECTS_MY = ['Fear', 'Pride', 'Security', 'Self-esteem', 'Sex-Relations', 'Personal Relationships'];

    public const MOODS = [
        'Happy', 'Decent', 'Surprised', 'Loved', 'Confused', 'Bored', 'Sad', 'Unhappy',
        'Embarrassed', 'Sick', 'Angry', 'Really Mad', 'Crying', 'Beaten Up', 'Scared', 'Tired',
    ];

    public const MORNING_QUESTIONS = [
        'q2' => 'Did you remind yourself about your powerlessness?',
        'q3' => 'Did you pray to God & ask him to keep you sober?',
        'q4' => 'Did you think about the 24 hours ahead?',
        'q5' => 'Did you meditate?',
    ];

    public const NIGHT_QUESTIONS = [
        'Were we resentful?',
        'Were we selfish?',
        'Were we dishonest?',
        'Were we afraid?',
        'Do we owe an apology?',
        'Have we kept something to ourselves which should be discussed with another person at once?',
        'Were we kind and loving toward all?',
        'What could we have done better?',
        'Were we thinking of ourselves most of the time?',
        'Were we thinking of others, of what we could pack into the stream of life?',
        'Did we drift into worry, remorse or morbid reflection?',
        "If required, did we ask for God's forgiveness and inquire what corrective measures should be taken?",
    ];

    /** @return array<int, array<string, mixed>> */
    public static function for(string $slug): array
    {
        return match ($slug) {
            'journals' => [
                ['column' => 'description', 'kind' => 'textarea', 'label' => 'Your entry', 'rows' => 10, 'required' => true],
            ],
            'gratitude' => [
                ['column' => 'description', 'kind' => 'textarea', 'label' => 'What are you grateful for?', 'rows' => 10, 'required' => true],
            ],
            'step-4', 'step-10' => [
                ['column' => 'invtitle', 'kind' => 'text', 'label' => 'I am resentful at / afraid of', 'required' => true],
                ['column' => 'invdescription', 'kind' => 'textarea', 'label' => 'The cause', 'rows' => 5],
                ['column' => 'affectsmy', 'kind' => 'tags', 'label' => 'Part of self which is affected', 'options' => self::AFFECTS_MY],
                ['column' => 'myfault', 'kind' => 'textarea', 'label' => 'What was my fault?', 'rows' => 5],
            ],
            'amends' => [
                ['column' => 'amendstitle', 'kind' => 'text', 'label' => 'I owe amends to', 'required' => true],
                ['column' => 'amendsfor', 'kind' => 'textarea', 'label' => 'Because', 'rows' => 5],
                ['column' => 'amendsnotes', 'kind' => 'textarea', 'label' => 'Notes', 'rows' => 4],
                ['column' => 'amendsdone', 'kind' => 'done', 'label' => 'I have made these amends'],
            ],
            'morning' => array_merge(
                [['column' => 'icons', 'kind' => 'mood', 'label' => 'How are you feeling today?', 'options' => self::MOODS]],
                array_map(
                    fn (string $column, string $question): array => [
                        'column' => $column, 'kind' => 'switch', 'label' => $question,
                    ],
                    array_keys(self::MORNING_QUESTIONS),
                    array_values(self::MORNING_QUESTIONS),
                ),
                [['column' => 'q6_notes', 'kind' => 'textarea', 'label' => 'Notes', 'rows' => 4]],
            ),
            'nightly' => self::nightly(),
            default => [],
        };
    }

    /** @return array<int, array<string, mixed>> */
    private static function nightly(): array
    {
        $fields = [];

        foreach (self::NIGHT_QUESTIONS as $i => $question) {
            $number = $i + 1;
            $switch = 'sw'.$number;

            // Question eight has no yes or no — it asks what could have been
            // done better. Night::SWITCHES is the authority on which exist.
            if (in_array($switch, Night::SWITCHES, true)) {
                $fields[] = ['column' => $switch, 'kind' => 'switch', 'label' => 'Q'.$number.'. '.$question];
                $fields[] = ['column' => 'desc'.$number, 'kind' => 'textarea', 'label' => 'Notes', 'rows' => 2, 'quiet' => true];

                continue;
            }

            $fields[] = ['column' => 'desc'.$number, 'kind' => 'textarea', 'label' => 'Q'.$number.'. '.$question, 'rows' => 4];
        }

        return $fields;
    }

    /** The columns a form for this type may write, and nothing else. */
    public static function columns(string $slug): array
    {
        return array_column(self::for($slug), 'column');
    }
}
