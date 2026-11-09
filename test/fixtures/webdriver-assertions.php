<?php

// Shared observations for protocol and report checks of every comparison assertion.
return [
    'visible' => [[], '/displayed', true, false],
    'valueEquals' => [['0'], '/property/value', '0', 0],
    'valueContains' => [['Saved'], '/property/value', 'Saved draft', 'pending'],
    'textEquals' => [['Saved'], '/text', 'Saved', 'pending'],
    'textContains' => [['Saved'], '/text', 'Saved draft', 'pending'],
    'textMatches' => [['~^Saved [0-9]+$~'], '/text', 'Saved 42', 'pending'],
    'attributeEquals' => [['data/name', 'Saved'], '/attribute/data%2Fname', 'Saved', null],
    'attributeContains' => [['data/name', 'Saved'], '/attribute/data%2Fname', 'Saved draft', null],
    'attributeMatches' => [['data/name', '~^Saved [0-9]+$~'], '/attribute/data%2Fname', 'Saved 42', null],
    'propertyEquals' => [['checked', false], '/property/checked', false, 'false'],
    'propertyContains' => [['items', ['a', 'b']], '/property/items', ['a', 'b', 'c'], ['a']],
    'propertyMatches' => [['status', '~^Saved [0-9]+$~'], '/property/status', 'Saved 42', 42],
    'cssEquals' => [['display', 'block'], '/css/display', 'block', 'none'],
    'enabled' => [[], '/enabled', true, false],
    'selected' => [[], '/selected', true, false],
    'hasAttribute' => [['data/name'], '/attribute/data%2Fname', '', null],
    'hasClass' => [[['one', 'two']], '/attribute/class', "one\ttwo extra", 'someone two'],
    'hasDescendants' => [[], '/elements', [['element-6066-11e4-a52e-4f735466cecf' => 'child']], []],
    'urlEquals' => [['https://example.test/done'], '/url', 'https://example.test/done', 'https://example.test/pending'],
    'urlContains' => [['/done'], '/url', 'https://example.test/done', 'https://example.test/pending'],
    'urlMatches' => [['~/done$~'], '/url', 'https://example.test/done', 'https://example.test/pending'],
    'titleEquals' => [['Saved'], '/title', 'Saved', 'pending'],
    'titleContains' => [['Saved'], '/title', 'Saved draft', 'pending'],
    'titleMatches' => [['~^Saved [0-9]+$~'], '/title', 'Saved 42', 'pending'],
];
