<?php

namespace App\Http\Controllers;

use App\Actions\Proposals\AcceptProposal;
use App\Models\Project;
use App\Models\Proposal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProposalAcceptanceController extends Controller
{
    public function __invoke(Request $request, Project $project, Proposal $proposal, AcceptProposal $acceptor): RedirectResponse
    {
        try {
            $result = $acceptor->accept($project->id, $proposal->id, $request->boolean('confirmed'));
        } catch (ValidationException $exception) {
            return to_route('client.projects.proposals.show', [$project, $proposal])->withErrors($exception->errors(), 'acceptance');
        }

        return to_route('client.projects.proposals.show', [$project, $proposal])->with('proposal-acceptance', $result['created']
            ? 'Proposal accepted. The project is now in progress and other submitted offers have been closed.'
            : 'This proposal is already accepted. The original assignment and agreed terms have been kept.');
    }
}
