<?php

return [
    'transitions' => [
        'orf' => [
            'draft' => ['submitted'],
            'submitted' => ['approved', 'declined'],
            'approved' => [],
            'declined' => ['draft'],
        ],
        'master_item' => [
            'inactive' => ['pending_accounting'],
            'pending_accounting' => ['active', 'declined'],
            'declined' => ['pending_accounting'],
            'active' => ['inactive'],
        ],
        'master_vendor' => [
            'draft' => ['active'],
            'active' => ['inactive'],
            'inactive' => ['active'],
        ],
        'mr' => [
            'draft' => ['pending_dept_head'],
            'pending_dept_head' => ['pending_pihak_ii', 'declined', 'approved'],
            'pending_pihak_ii' => ['approved', 'declined'],
            'approved' => ['pr_created'],
            'declined' => ['pending_dept_head'],
        ],
        'mr_flow_a' => [
            'draft' => ['pending_dept_head'],
            'pending_dept_head' => ['pending_pihak_ii', 'declined'],
            'pending_pihak_ii' => ['approved', 'declined'],
            'approved' => ['pr_created'],
        ],
        'mr_flow_b' => [
            'draft' => ['pending_dept_head'],
            'pending_dept_head' => ['approved', 'declined'],
            'approved' => ['pr_created'],
        ],
        'sr' => [
            'draft' => ['pending_dept_head'],
            'pending_dept_head' => ['pending_pihak_ii', 'declined', 'approved'],
            'pending_pihak_ii' => ['approved', 'declined'],
            'approved' => ['pr_created'],
            'declined' => ['pending_dept_head'],
        ],
        'pr' => [
            'auto_created' => ['pending_pihak_i_pricing'],
            'pending_pihak_i_pricing' => ['pending_pihak_ii'],
            'pending_pihak_ii' => ['forwarded_to_p3', 'declined'],
            'forwarded_to_p3' => [],
            'declined' => ['pending_pihak_i_pricing'],
        ],
        'po' => [
            'draft' => ['pending_pihak_ii'],
            'pending_pihak_ii' => ['approved', 'declined'],
            'approved' => ['open'],
            'open' => ['partially_closed', 'closed'],
            'partially_closed' => ['closed'],
            'declined' => ['pending_pihak_ii'],
        ],
        'pre_rd' => [
            'draft' => ['confirmed'],
            'confirmed' => ['rd_generated'],
        ],
        'rd' => [
            'pending_input' => ['validating'],
            'validating' => ['approved', 'declined'],
            'approved' => ['asset_tagged'],
        ],
        'wo' => [
            'draft' => ['pending_approval'],
            'pending_approval' => ['approved', 'declined'],
            'approved' => ['al_generated'],
            'declined' => ['draft'],
        ],
        'al' => [
            'auto_created' => ['pending_approval'],
            'pending_approval' => ['approved', 'declined'],
            'approved' => ['in_progress'],
            'in_progress' => ['completed'],
            'declined' => ['pending_approval'],
        ],
        'di' => [
            'draft' => ['issued'],
        ],
        'dn' => [
            'draft' => ['dispatched'],
        ],
        'rrv' => [
            'draft' => ['confirmed'],
        ],
    ],
];