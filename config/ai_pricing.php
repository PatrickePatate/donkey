<?php

/*
|--------------------------------------------------------------------------
| Model pricing
|--------------------------------------------------------------------------
|
| US dollars per million tokens, used to estimate what each AI call costs.
| Anthropic first-party API rates as of November 2026; adjust them if you
| use another provider or negotiated pricing. Calls to a model missing from
| this list are recorded with their tokens and a cost of zero.
|
*/

return [

    'models' => [
        'claude-fable-5-2' => ['input' => 8.00, 'output' => 40.00],
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
        'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
    ],

    /*
    | Prompt-cache prices, as a share of the input price, for models without
    | an explicit cache price. Anthropic: reads 0.1×, writes 1.25×.
    */

    'cache_read_ratio' => 0.1,
    'cache_write_ratio' => 1.25,

    /*
    | Owners get an email when the month's spend crosses these shares of the budget.
    */

    'alert_thresholds' => [0.8, 1.0],

];
