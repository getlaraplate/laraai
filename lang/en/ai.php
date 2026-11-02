<?php

declare(strict_types=1);

return [
    'actions' => [
        'reanalyze' => 'Re-analyze',
    ],
    'fields' => [
        'ai_analysis_model' => 'Model',
        'ai_analysis_status' => 'Status',
        'ai_entities' => 'Entities',
        'ai_idea' => 'Idea',
        'ai_intent' => 'Intent',
        'ai_ocr_text' => 'OCR text',
        'ai_provenance' => 'AI-generated fields',
        'ai_transcript' => 'Transcript',
    ],
    'messages' => [
        'egressLeaves' => 'The text sent to this model leaves the installation: an external service receives it.',
        'egressStays' => 'The text sent to this model stays on a server declared by this installation.',
        'egressTitle' => 'Text leaves the installation',
        'egressUnknown' => 'It cannot be told whether the text sent to this model leaves the installation.',
        'embeddingLanguagesMissing' => 'The embedding model :profile does not declare these languages of the installation: :languages. Search in them may be less accurate.',
        'reanalysisQueued' => 'Re-analysis queued',
        'reanalyzeDescription' => 'Queue a fresh AI analysis of this media file.',
    ],
    'sections' => [
        'aiAnalysis' => 'AI analysis',
    ],
];
