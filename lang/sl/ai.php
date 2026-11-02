<?php

declare(strict_types=1);

return [
    'actions' => [
        'reanalyze' => 'Ponovno analiziraj',
    ],
    'fields' => [
        'ai_analysis_model' => 'Model',
        'ai_analysis_status' => 'Stanje',
        'ai_entities' => 'Entitete',
        'ai_idea' => 'Ideja',
        'ai_intent' => 'Namen',
        'ai_ocr_text' => 'Besedilo OCR',
        'ai_provenance' => 'Polja, ki jih je ustvarila UI',
        'ai_transcript' => 'Prepis',
    ],
    'messages' => [
        'egressLeaves' => 'Besedilo, poslano temu modelu, zapusti namestitev: prejme ga zunanja storitev.',
        'egressStays' => 'Besedilo, poslano temu modelu, ostane na strežniku, ki ga navaja ta namestitev.',
        'egressTitle' => 'Besedilo zapusti namestitev',
        'egressUnknown' => 'Ni mogoče ugotoviti, ali besedilo, poslano temu modelu, zapusti namestitev.',
        'embeddingLanguagesMissing' => 'Model vdelav :profile ne navaja teh jezikov namestitve: :languages. Iskanje v njih je lahko manj natančno.',
        'reanalysisQueued' => 'Ponovna analiza v čakalni vrsti',
        'reanalyzeDescription' => 'V čakalno vrsto postavi novo analizo UI te medijske datoteke.',
    ],
    'sections' => [
        'aiAnalysis' => 'Analiza UI',
    ],
];
