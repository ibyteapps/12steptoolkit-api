<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LegacySchema;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit');

/*
 * The adopted tables are not created by a migration — this application does not
 * own them, and its first rule is that it never alters one. The suite builds the
 * reconstruction instead; `Tests\Support\LegacySchema` says how much of it is
 * established fact and how much is inferred from the PHP.
 */
pest()->beforeEach(fn () => LegacySchema::create())->in('Feature', 'Unit');
