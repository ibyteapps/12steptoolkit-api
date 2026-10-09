<?php

/*
|--------------------------------------------------------------------------
| The home page's frequently asked questions
|--------------------------------------------------------------------------
|
| One source for both the accordion a visitor reads and the `FAQPage`
| structured data Google reads, so the two cannot disagree — which is the
| arrangement the static site used and the reason it is kept. Google treats
| marked-up answers that do not appear on the page as a reason for a manual
| action, so these must stay the same text in both places.
|
| Converted from `data/faqs.js` by letting Node evaluate it and dumping JSON.
| `answer` is a list of paragraphs; the schema joins them with a space, which
| is what `faqsForSchema()` did.
*/

return [
    [
        'id' => 'best-free-aa-app',
        'question' => 'What makes 12 Step Toolkit the best free A.A. app?',
        'answer' => [
            'Rooted in the trusted principles of Alcoholics Anonymous, this free 12 step app empowers you to track sobriety milestones, work your 12 steps with or without a sponsor and celebrate every alcohol-free day with precision and ease. Its intuitive design lets you log progress, set personal goals, and access the Big Book of A.A. and all of the other literature when you need it.',
            'In addition to tracking your journey, the 12 Step Toolkit app connects you with a supportive community inspired by A.A. traditions. Whether you\'re just beginning or well on your path to recovery, our app offers a blend of proven recovery strategies and modern tools that help reinforce your commitment to sobriety and foster long-lasting change.',
        ],
    ],
    [
        'id' => 'long-term-sobriety',
        'question' => 'Can I maintain long term sobriety with 12 Step Toolkit?',
        'answer' => [
            'Yes, definitely. Our members have used our app for years, and some members with many decades of sobriety recommend this app to their sponsees. The daily reminders for doing your morning and night-time inventories add another layer to your sobriety. Along with this, the app also sends out motivational hourly consciousness notifications directly to your phone. Many new members have told us this feature has helped them stay sober.',
            'Working the 12 Steps of A.A. is the key to sobriety. The app lets you work all of the 12 Steps, one day at a time.',
        ],
    ],
    [
        'id' => 'online-sponsors',
        'question' => 'How does 12 Step Toolkit have 11,000 online sponsors?',
        'answer' => [
            'The app allows users with longer sobriety to start sponsoring newer members. You will find over 11,000 sponsors from around the world. Just send a request to a sponsor and you will be on your way in no time.',
            'Your sponsor can see your 12 Step work live as you write your inventories, and they can guide you on every step and every inventory. You can also chat with your sponsor completely anonymously. Once you have enough sober time and are confident enough, you can also start sponsoring newer A.A. members.',
        ],
    ],
    [
        'id' => 'is-it-free',
        'question' => 'Is 12 Step Toolkit free?',
        'answer' => [
            'Yes, 100%. It is free to download and the app is ad-supported, which helps us maintain and further develop it. All features are unlocked in the free version with some minor restrictions that will not stop you completing your 12 Steps, chatting with your sponsor, or reading the 164 pages of the Big Book.',
            'As always, we ask members to support us with quarterly or annual subscriptions whenever they can. This helps us keep going and helps the still-suffering alcoholic. If you cannot afford a subscription plan, there is also a reasonably priced lifetime plan.',
        ],
    ],
    [
        'id' => 'made-by-aa-members',
        'question' => 'Is 12 Step Toolkit made by A.A. members?',
        'answer' => [
            'Yes. This app has been designed by an A.A. member and is supported by many other sober A.A. members with long term sobriety.',
            'We have taken feedback from many members over the years on how to improve the app, and we are always open to new suggestions.',
        ],
    ],
];
