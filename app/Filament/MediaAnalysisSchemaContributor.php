<?php

declare(strict_types=1);

namespace Modules\AI\Filament;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisGate;
use Modules\AI\Jobs\AnalyzeMediaJob;
use Modules\AI\Models\MediaAnalysis;
use Modules\Core\Filament\Contracts\IResourceSchemaContributor;
use Modules\Core\Models\Media;

/**
 * Contributes the AI analysis surface to a media's Filament view (M22), through
 * Core's resource-schema seam so Core never references AI. Gated by the M18 master
 * switch: a read-only section (idea/intent/entities/transcript/OCR/status +
 * provenance) plus a "Re-analyze" action that re-queues {@see AnalyzeMediaJob}. The
 * analysis is looked up by the media's `content_hash` (M15), shared across copies.
 */
final readonly class MediaAnalysisSchemaContributor implements IResourceSchemaContributor
{
    public function __construct(private MediaAnalysisGate $gate) {}

    public function contributesTo(): string
    {
        return Media::class;
    }

    public function infolistSections(Model $record): array
    {
        if (! $record instanceof Media || ! $this->gate->enabled()) {
            return [];
        }

        $analysis = $this->analysisFor($record);

        if (! $analysis instanceof MediaAnalysis) {
            return [];
        }

        return [
            Section::make(__('ai.sections.aiAnalysis'))
                ->columns(2)
                ->schema([
                    TextEntry::make('ai_analysis_status')
                        ->label(__('ai.fields.ai_analysis_status'))
                        ->badge()
                        ->state($analysis->analysis_status->value),
                    TextEntry::make('ai_analysis_model')
                        ->label(__('ai.fields.ai_analysis_model'))
                        ->state($analysis->analysis_model_version)
                        ->placeholder('—'),
                    TextEntry::make('ai_idea')
                        ->label(__('ai.fields.ai_idea'))
                        ->columnSpanFull()
                        ->state($analysis->idea)
                        ->placeholder('—'),
                    TextEntry::make('ai_intent')
                        ->label(__('ai.fields.ai_intent'))
                        ->columnSpanFull()
                        ->state($analysis->intent)
                        ->placeholder('—'),
                    TextEntry::make('ai_entities')
                        ->label(__('ai.fields.ai_entities'))
                        ->badge()
                        ->state($analysis->entities ?? []),
                    TextEntry::make('ai_provenance')
                        ->label(__('ai.fields.ai_provenance'))
                        ->badge()
                        ->state(array_keys($analysis->provenance ?? [])),
                    TextEntry::make('ai_transcript')
                        ->label(__('ai.fields.ai_transcript'))
                        ->columnSpanFull()
                        ->state($analysis->transcript)
                        ->placeholder('—'),
                    TextEntry::make('ai_ocr_text')
                        ->label(__('ai.fields.ai_ocr_text'))
                        ->columnSpanFull()
                        ->state($analysis->ocr_text)
                        ->placeholder('—'),
                ]),
        ];
    }

    public function recordActions(Model $record): array
    {
        if (! $record instanceof Media || ! $this->gate->enabled()) {
            return [];
        }

        return [
            Action::make('reanalyzeMedia')
                ->label(__('ai.actions.reanalyze'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalDescription(__('ai.messages.reanalyzeDescription'))
                ->action(static function () use ($record): void {
                    dispatch(new AnalyzeMediaJob($record));

                    Notification::make()
                        ->title(__('ai.messages.reanalysisQueued'))
                        ->success()
                        ->send();
                }),
        ];
    }

    private function analysisFor(Media $media): ?MediaAnalysis
    {
        $hash = $media->custom_properties['content_hash'] ?? null;

        return is_string($hash) ? MediaAnalysis::query()->firstWhere('content_hash', $hash) : null;
    }
}
