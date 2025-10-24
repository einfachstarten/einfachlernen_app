<?php
/**
 * Shared Calendly service catalog.
 *
 * Returns an associative array keyed by service slug with Calendly metadata.
 */

return [
    'lerntraining' => [
        'uri' => 'https://api.calendly.com/event_types/ADE2NXSJ5RCEO3YV',
        'url' => 'https://calendly.com/einfachlernen/lerntraining',
        'dur' => 50,
        'name' => 'Lerntraining',
    ],
    'neurofeedback-20' => [
        'uri' => 'https://api.calendly.com/event_types/ec567e31-b98b-4ed4-9beb-b01c32649b9b',
        'url' => 'https://calendly.com/einfachlernen/neurofeedback-training-20-min',
        'dur' => 20,
        'name' => 'Neurofeedback 20 Min',
    ],
    'neurofeedback-40' => [
        'uri' => 'https://api.calendly.com/event_types/2ad6fc6d-7a65-42dd-ba6e-0135945ebb9a',
        'url' => 'https://calendly.com/einfachlernen/neurofeedback-training-40-minuten',
        'dur' => 40,
        'name' => 'Neurofeedback 40 Min',
    ],
];
