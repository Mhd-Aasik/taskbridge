<?php

namespace App\Http\Controllers;

use App\Actions\Projects\PublishProject;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProjectPublicationController extends Controller
{
    public function __invoke(Request $request, Project $project, PublishProject $publisher): RedirectResponse
    {
        try {
            $published = $publisher->publish($request->user(), $project->id, $request->boolean('confirmed'));
        } catch (ValidationException $exception) {
            return to_route('client.projects.show', $project)->withErrors($exception->errors(), 'publication');
        }

        return to_route('client.projects.show', $project)->with('project-publication', $published
            ? 'Project published. It is now open for freelancer applications.'
            : 'This project is already published. Its original publication time has been kept.');
    }
}
