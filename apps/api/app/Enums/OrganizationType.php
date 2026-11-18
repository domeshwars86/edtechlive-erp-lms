<?php

namespace App\Enums;

enum OrganizationType: string
{
    case School = 'school';
    case College = 'college';
    case CoachingInstitute = 'coaching_institute';
    case Academy = 'academy';
    case IndependentEducator = 'independent_educator';
}
