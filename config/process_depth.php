<?php

use App\Support\ProcessDepth\ProcessDepth;

return [
    'default' => ProcessDepth::STANDARD,

    'capabilities' => [
        ProcessDepth::FAST => [
            'operation_card_density' => 'compact',
            'show_extended_readiness_details' => false,
            'show_evidence_sections' => false,
            'show_quality_control_section' => false,
            'show_advanced_activity_timeline' => false,
            'show_batch_operation_controls' => false,
        ],
        ProcessDepth::STANDARD => [
            'operation_card_density' => 'standard',
            'show_extended_readiness_details' => true,
            'show_evidence_sections' => true,
            'show_quality_control_section' => true,
            'show_advanced_activity_timeline' => true,
            'show_batch_operation_controls' => true,
        ],
        ProcessDepth::CONTROLLED => [
            'operation_card_density' => 'detailed',
            'show_extended_readiness_details' => true,
            'show_evidence_sections' => true,
            'show_quality_control_section' => true,
            'show_advanced_activity_timeline' => true,
            'show_batch_operation_controls' => true,
        ],
    ],
];
