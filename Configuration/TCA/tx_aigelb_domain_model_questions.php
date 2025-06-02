<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:aigelb/Resources/Private/Language/locallang_db.xlf:tx_aigelb_domain_model_questions',
        'label' => 'question',
        'label_alt' => 'agent',
        'label_alt_force' => false,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'sortby' => 'sorting',
        'versioningWS' => true,
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
        ],
        'searchFields' => 'question',
        'iconfile' => 'EXT:aigelb/Resources/Public/Icons/tx_aigelb_domain_model_questions.gif',
    ],
    'types' => [
        '1' => ['showitem' => 'sys_language_uid, l10n_parent, l10n_diffsource, hidden, agent, question, --div--;LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:tabs.access, starttime, endtime'],
    ],
    'columns' => [
        'sys_language_uid' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.language',
            'config' => ['type' => 'language'],
        ],
        'l10n_parent' => [
            'displayCond' => 'FIELD:sys_language_uid:>:0',
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.l18n_parent',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => 0,
                'items' => [
                    ['label' => '', 'value' => 0],
                ],
                'foreign_table' => 'tx_aigelb_domain_model_questions',
                'foreign_table_where' => 'AND {#tx_aigelb_domain_model_questions}.{#pid}=###CURRENT_PID### AND {#tx_aigelb_domain_model_questions}.{#sys_language_uid} IN (-1,0)',
            ],
        ],
        'l10n_diffsource' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.visible',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'items' => [
                    [
                        'label' => '',
                        'invertStateDisplay' => true,
                    ],
                ],
            ],
        ],
        'starttime' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.starttime',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],
        'endtime' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.endtime',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
                'range' => [
                    'upper' => mktime(0, 0, 0, 1, 1, 2038),
                ],
                'behaviour' => [
                    'allowLanguageSynchronization' => true,
                ],
            ],
        ],

        'question' => [
            'exclude' => true,
            'label' => 'LLL:EXT:aigelb/Resources/Private/Language/locallang_db.xlf:tx_aigelb_domain_model_questions.question',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 6,
                'eval' => 'trim,required',
                'max' => 1000,
            ],
        ],

        'agent' => [
            'exclude' => false,
            'label' => 'LLL:EXT:aigelb/Resources/Private/Language/locallang_db.xlf:tx_aigelb_domain_model_questions.agent',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_aigelb_domain_model_agent',
                'foreign_table_where' => 'ORDER BY tx_aigelb_domain_model_agent.title',
                'items' => [
                    ['label' => 'LLL:EXT:aigelb/Resources/Private/Language/locallang_db.xlf:tx_aigelb_domain_model_questions.agent.pleaseSelect', 'value' => 0],
                ],
                'default' => 0,
                'size' => 1,
                'maxitems' => 1,
                'minitems' => 1,
                'eval' => 'required',
            ],
        ],
    ],
];
