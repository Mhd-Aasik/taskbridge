<?php

namespace App\Enums;

enum ProposalStatus: string
{
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
}
