<?php

declare(strict_types=1);

return [
    'actions' => [
        'reanalyze' => 'Rianalizza',
    ],
    'fields' => [
        'ai_analysis_model' => 'Modello',
        'ai_analysis_status' => 'Stato',
        'ai_entities' => 'Entità',
        'ai_idea' => 'Idea',
        'ai_intent' => 'Intento',
        'ai_ocr_text' => 'Testo OCR',
        'ai_provenance' => 'Campi generati dall\'IA',
        'ai_transcript' => 'Trascrizione',
    ],
    'messages' => [
        'embeddingLanguagesMissing' => 'Il modello di embedding :profile non dichiara queste lingue dell\'installazione: :languages. La ricerca in esse potrebbe essere meno accurata.',
        'reanalysisQueued' => 'Rianalisi in coda',
        'reanalyzeDescription' => 'Accoda una nuova analisi IA di questo file multimediale.',
    ],
    'sections' => [
        'aiAnalysis' => 'Analisi IA',
    ],
];
