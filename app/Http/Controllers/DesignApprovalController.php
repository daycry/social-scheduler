<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\Post;
use App\Models\Studio\Design;
use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DesignApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function requestApproval(Request $request, Design $design): JsonResponse
    {
        $pending = $this->approvals->requestDesign($request->user(), $design);

        return response()->json($this->approvals->presentDesign($pending));
    }

    public function approve(Request $request, Design $design): JsonResponse
    {
        return $this->decide($request, $design, Post::APPROVAL_APPROVED);
    }

    public function reject(Request $request, Design $design): JsonResponse
    {
        return $this->decide($request, $design, Post::APPROVAL_REJECTED);
    }

    private function decide(Request $request, Design $design, string $status): JsonResponse
    {
        try {
            $decided = $this->approvals->decideDesign($request->user(), $design, $status, $request->input('comment'), $request->input('signature'));
        } catch (PostValidationException $exception) {
            throw ValidationException::withMessages($exception->errors());
        }

        return response()->json($this->approvals->presentDesign($decided));
    }
}
