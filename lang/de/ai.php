<?php

declare(strict_types=1);

return [
    'actions' => [
        'reanalyze' => 'Erneut analysieren',
    ],
    'fields' => [
        'ai_analysis_model' => 'Modell',
        'ai_analysis_status' => 'Status',
        'ai_entities' => 'Entitäten',
        'ai_idea' => 'Idee',
        'ai_intent' => 'Absicht',
        'ai_ocr_text' => 'OCR-Text',
        'ai_provenance' => 'KI-generierte Felder',
        'ai_transcript' => 'Transkript',
    ],
    'messages' => [
        'egressLeaves' => 'Der an dieses Modell gesendete Text verlässt die Installation: Ein externer Dienst empfängt ihn.',
        'egressStays' => 'Der an dieses Modell gesendete Text bleibt auf einem Server, den diese Installation deklariert.',
        'egressTitle' => 'Text verlässt die Installation',
        'egressUnknown' => 'Ob der an dieses Modell gesendete Text die Installation verlässt, lässt sich nicht feststellen.',
        'embeddingLanguagesMissing' => 'Das Embedding-Modell :profile deklariert diese Sprachen der Installation nicht: :languages. Die Suche in diesen Sprachen kann ungenauer sein.',
        'reanalysisQueued' => 'Erneute Analyse eingereiht',
        'reanalyzeDescription' => 'Eine neue KI-Analyse dieser Mediendatei einreihen.',
    ],
    'sections' => [
        'aiAnalysis' => 'KI-Analyse',
    ],
];
