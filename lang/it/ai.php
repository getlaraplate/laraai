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
        'egressLeaves' => 'Il testo inviato a questo modello esce dall\'installazione: lo riceve un servizio esterno.',
        'egressStays' => 'Il testo inviato a questo modello resta su un server dichiarato da questa installazione.',
        'egressTitle' => 'Il testo esce dall\'installazione',
        'egressUnknown' => 'Non si può stabilire se il testo inviato a questo modello esca dall\'installazione.',
        'embeddingLanguagesMissing' => 'Il modello di embedding :profile non dichiara queste lingue dell\'installazione: :languages. La ricerca in esse potrebbe essere meno accurata.',
        'reanalysisQueued' => 'Rianalisi in coda',
        'reanalyzeDescription' => 'Accoda una nuova analisi IA di questo file multimediale.',
    ],
    'sections' => [
        'aiAnalysis' => 'Analisi IA',
    ],
];
