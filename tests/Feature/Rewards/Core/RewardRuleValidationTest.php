<?php

use App\Enums\Rewards\RewardProgramMode;
use App\Support\Rewards\RewardRuleValidator;
use Tests\Feature\Rewards\Core\Support\RewardCoreTestFixtures;

it('accepts validator-clean rules and terms for every mode', function (RewardProgramMode $mode) {
    $validator = new RewardRuleValidator;

    expect($validator->errors(RewardCoreTestFixtures::rules($mode), $mode, 'own-family'))->toBe([])
        ->and($validator->termsErrors(RewardCoreTestFixtures::terms($mode)))->toBe([]);
})->with(RewardProgramMode::cases());

it('rejects unknown keys at every level', function () {
    $validator = new RewardRuleValidator;
    $rules = RewardCoreTestFixtures::rules();
    $rules['expression'] = 'points * 2';

    expect($validator->errors($rules, RewardProgramMode::Points, 'own-family'))
        ->toContain('Unknown rules key [expression].');

    $rules = RewardCoreTestFixtures::rules();
    $rules['components'][0]['formula'] = '1+1';

    expect($validator->errors($rules, RewardProgramMode::Points, 'own-family')[0])->toContain('unknown key [formula]');
});

it('enforces per-component amount shapes', function () {
    $validator = new RewardRuleValidator;

    $recognition = RewardCoreTestFixtures::rules(RewardProgramMode::Recognition);
    $recognition['components'][0]['points'] = 5;
    expect($validator->errors($recognition, RewardProgramMode::Recognition, 'own-family')[0])->toContain('must not carry [points]');

    $points = RewardCoreTestFixtures::rules();
    $points['components'][0]['points'] = 0;
    expect($validator->errors($points, RewardProgramMode::Points, 'own-family')[0])->toContain('integer 1..');

    $points = RewardCoreTestFixtures::rules();
    $points['components'][0]['points'] = 1.5;
    expect($validator->errors($points, RewardProgramMode::Points, 'own-family')[0])->toContain('integer 1..');

    $points = RewardCoreTestFixtures::rules();
    $points['components'][0]['currency'] = 'MYR';
    expect($validator->errors($points, RewardProgramMode::Points, 'own-family')[0])->toContain('must not carry [currency]');

    $cash = RewardCoreTestFixtures::rules(RewardProgramMode::Cash);
    unset($cash['components'][0]['currency']);
    expect($validator->errors($cash, RewardProgramMode::Cash, 'own-family')[0])->toContain('currency must be one of');

    $cash = RewardCoreTestFixtures::rules(RewardProgramMode::Cash);
    $cash['components'][0]['currency'] = 'XXX';
    expect($validator->errors($cash, RewardProgramMode::Cash, 'own-family')[0])->toContain('currency must be one of');

    $benefit = RewardCoreTestFixtures::rules(RewardProgramMode::Benefit);
    $benefit['components'][0]['conditions'] = [];
    expect($validator->errors($benefit, RewardProgramMode::Benefit, 'own-family')[0])->toContain('explicit list');
});

it('rejects amount overflow and duplicate components', function () {
    $validator = new RewardRuleValidator;

    $points = RewardCoreTestFixtures::rules();
    $points['components'][0]['points'] = RewardRuleValidator::MAX_POINTS + 1;
    expect($validator->errors($points, RewardProgramMode::Points, 'own-family')[0])->toContain('integer 1..');

    $cash = RewardCoreTestFixtures::rules(RewardProgramMode::Cash);
    $cash['components'][0]['minor_units'] = RewardRuleValidator::MAX_MINOR_UNITS + 1;
    expect($validator->errors($cash, RewardProgramMode::Cash, 'own-family')[0])->toContain('integer 1..');

    $combined = RewardCoreTestFixtures::rules(RewardProgramMode::Combined);
    $combined['components'][] = ['type' => 'points', 'points' => 5, 'unit' => 'pts'];
    expect($validator->errors($combined, RewardProgramMode::Combined, 'own-family')[0])->toContain('Duplicate component');
});

it('rejects unknown outcomes and circular stacking', function () {
    $validator = new RewardRuleValidator;

    $rules = RewardCoreTestFixtures::rules();
    $rules['eligibility']['outcomes'][] = 'teleport_visit';
    expect($validator->errors($rules, RewardProgramMode::Points, 'own-family')[0])->toContain('allowlisted outcome');

    $rules = RewardCoreTestFixtures::rules();
    $rules['stacking']['compatible_with'] = ['own-family'];
    expect($validator->errors($rules, RewardProgramMode::Points, 'own-family')[0])->toContain('circular');
});

it('requires verified evidence for cash and benefit components', function () {
    $validator = new RewardRuleValidator;

    $cash = RewardCoreTestFixtures::rules(RewardProgramMode::Cash);
    $cash['eligibility']['min_verification'] = 'accepted';
    expect($validator->errors($cash, RewardProgramMode::Cash, 'own-family')[0])
        ->toContain('accepted registration is not attendance');

    $benefit = RewardCoreTestFixtures::rules(RewardProgramMode::Benefit);
    $benefit['eligibility']['min_verification'] = 'unverified';
    expect($validator->errors($benefit, RewardProgramMode::Benefit, 'own-family')[0])
        ->toContain('staff_verified or higher');
});

it('rejects malformed terms snapshots', function () {
    $validator = new RewardRuleValidator;

    expect($validator->termsErrors([])[0])->toContain('Terms label');

    $terms = RewardCoreTestFixtures::terms();
    $terms['material'] = [];
    expect($validator->termsErrors($terms)[0])->toContain('non-empty associative map');
});
