<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Repository;
use App\Models\ClinicalNote;

/**
 * Clinical notes, including the AI's drafts (§5, §28).
 *
 * Moved out of AiAssistantService per §18. The service decides whether a note
 * may be approved or discarded; this writes the decision down.
 */
final class ClinicalNoteRepository extends Repository
{
    protected string $model = ClinicalNote::class;

    /**
     * Sign a draft off, optionally with the clinician's corrections.
     *
     * approved_by says who put their name to it. updated_by is set too,
     * because approving is also an edit — the body may have been rewritten on
     * the way through, and §5 asks every row who last touched it.
     */
    public function approve(int $id, string $body, ?int $approvedBy): void
    {
        Database::statement(
            'UPDATE clinical_notes
                SET body = :body, approved_by = :by, approved_at = :now,
                    updated_by = :by2, updated_at = :now2
              WHERE organization_id = :org AND id = :id',
            [
                'body' => $body,
                'by'   => $approvedBy,
                'by2'  => $approvedBy,
                'now'  => now(),
                'now2' => now(),
                'org'  => $this->scopeBinding(),
                'id'   => $id,
            ],
        );
    }

    /**
     * Throw away an unapproved draft.
     *
     * A real delete, not a flag — an unapproved draft was never part of the
     * record, so there is nothing to retain. The service is what refuses to
     * call this for an approved note.
     */
    public function discard(int $id): void
    {
        Database::statement(
            'DELETE FROM clinical_notes WHERE organization_id = :org AND id = :id',
            ['org' => $this->scopeBinding(), 'id' => $id],
        );
    }
}
