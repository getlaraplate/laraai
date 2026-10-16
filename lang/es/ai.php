<?php

declare(strict_types=1);

return [
    'actions' => [
        'reanalyze' => 'Volver a analizar',
    ],
    'fields' => [
        'ai_analysis_model' => 'Modelo',
        'ai_analysis_status' => 'Estado',
        'ai_entities' => 'Entidades',
        'ai_idea' => 'Idea',
        'ai_intent' => 'Intención',
        'ai_ocr_text' => 'Texto OCR',
        'ai_provenance' => 'Campos generados por IA',
        'ai_transcript' => 'Transcripción',
    ],
    'messages' => [
        'reanalysisQueued' => 'Nuevo análisis en cola',
        'reanalyzeDescription' => 'Poner en cola un nuevo análisis de IA de este archivo multimedia.',
    ],
    'sections' => [
        'aiAnalysis' => 'Análisis de IA',
    ],
];
