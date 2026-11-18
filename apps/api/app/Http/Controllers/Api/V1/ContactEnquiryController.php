<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ContactEnquiry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

class ContactEnquiryController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeInbox($request);
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['new', 'in_progress', 'resolved', 'archived'])],
            'q' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ContactEnquiry::query()->with('reviewer:id,name')->orderByRaw("CASE WHEN status = 'new' THEN 0 WHEN status = 'in_progress' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at');
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['q'] ?? null)) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($filters['q'])).'%';
            $query->where(fn (Builder $matches) => $matches->where('name', 'like', $term)
                ->orWhere('email', 'like', $term)->orWhere('organization_name', 'like', $term)
                ->orWhere('message', 'like', $term));
        }

        return response()->json($query->paginate($filters['per_page'] ?? 25));
    }

    public function update(Request $request, ContactEnquiry $enquiry): JsonResponse
    {
        $this->authorizeInbox($request);
        $data = $request->validate(['status' => ['required', Rule::in(['new', 'in_progress', 'resolved', 'archived'])]]);
        $enquiry->forceFill([
            'status' => $data['status'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        return response()->json(['data' => $enquiry->refresh()->load('reviewer:id,name')]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9() .-]{7,30}$/'],
            'institution' => ['nullable', 'string', 'max:180'],
            'role' => ['required', Rule::in(['School or institution', 'Teacher or educator', 'Student', 'Parent or family member', 'Other'])],
            'category' => ['required', Rule::in(['Platform overview', 'Institution workflows', 'Teaching and courses', 'Partnership', 'Other'])],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'consent' => ['required', 'accepted'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (filled($data['website'] ?? null)) {
            return response()->json(['message' => 'Enquiry received.', 'data' => ['notification_sent' => false]], 202);
        }

        $enquiry = ContactEnquiry::query()->create([
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
            'organization_name' => $data['institution'] ?? null,
            'role' => $data['role'],
            'category' => $data['category'],
            'message' => trim($data['message']),
            'consented_at' => now(),
            'status' => 'new',
        ]);

        $recipient = config('services.enquiries.recipient');
        $notificationSent = false;
        if ($recipient && ! in_array(config('mail.default'), ['array', 'log'], true)) {
            try {
                Mail::raw(
                    "New edTechLive enquiry #{$enquiry->id}\n\nName: {$enquiry->name}\nEmail: {$enquiry->email}\nPhone: ".($enquiry->phone ?: 'Not provided')."\nOrganization: ".($enquiry->organization_name ?: 'Not provided')."\nRole: {$enquiry->role}\nCategory: {$enquiry->category}\n\nMessage:\n{$enquiry->message}",
                    function (Message $mail) use ($recipient, $enquiry): void {
                        $mail->to($recipient)
                            ->replyTo($enquiry->email, $enquiry->name)
                            ->subject("New edTechLive enquiry #{$enquiry->id}");
                    },
                );
                $notificationSent = true;
                $enquiry->update(['notified_at' => now()]);
            } catch (Throwable $exception) {
                Log::warning('Contact enquiry notification could not be sent.', ['enquiry_id' => $enquiry->id]);
            }
        }

        return response()->json([
            'message' => $notificationSent
                ? 'Your enquiry has been received and sent to the edTechLive team.'
                : 'Your enquiry has been saved. Email follow-up is not configured yet.',
            'data' => ['id' => $enquiry->id, 'notification_sent' => $notificationSent],
        ], 202);
    }

    private function authorizeInbox(Request $request): void
    {
        $allowedEmails = array_map('mb_strtolower', config('services.enquiries.admin_emails', []));
        abort_unless(in_array(mb_strtolower($request->user()->email), $allowedEmails, true), 403);
    }
}
