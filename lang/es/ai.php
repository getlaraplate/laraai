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
        'egressLeaves' => 'El texto enviado a este modelo sale de la instalación: lo recibe un servicio externo.',
        'egressStays' => 'El texto enviado a este modelo permanece en un servidor declarado por esta instalación.',
        'egressTitle' => 'El texto sale de la instalación',
        'egressUnknown' => 'No se puede determinar si el texto enviado a este modelo sale de la instalación.',
        'embeddingLanguagesMissing' => 'El modelo de embeddings :profile no declara estos idiomas de la instalación: :languages. La búsqueda en ellos puede ser menos precisa.',
        'reanalysisQueued' => 'Nuevo análisis en cola',
        'reanalyzeDescription' => 'Poner en cola un nuevo análisis de IA de este archivo multimedia.',
    ],
    'sections' => [
        'aiAnalysis' => 'Análisis de IA',
    ],
];
