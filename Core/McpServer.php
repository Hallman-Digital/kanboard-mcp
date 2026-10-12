<?php
declare(strict_types=1);

namespace Kanboard\Plugin\ModelContextProtocol\Core;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Model\ColumnModel;
use Kanboard\Model\ProjectModel;
use Kanboard\Model\SubtaskModel;
use Kanboard\Model\TaskModel;
use InvalidArgumentException;
use Throwable;
use Traversable;

/**
 * MCP Server for Kanboard
 * Implements Model Context Protocol JSON-RPC 2.0 specification
 * Provides comprehensive project management tools for AI assistants
 */
class McpServer extends Base
{
    private const LATEST_PROTOCOL_VERSION = '2025-11-25';

    /**
     * Maintained for compatibility with clients that still negotiate older revisions.
     *
     * @var array<int,string>
     */
    private const SUPPORTED_PROTOCOL_VERSIONS = [
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
        '2024-11-05',
    ];

    public function __construct($container)
    {
        $this->container = $container;
    }
    
    /**
     * Handle MCP JSON-RPC request
     */
    public function handleRequest(array $request): ?array
    {
        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== '2.0') {
            return $this->errorResponse(-32600, 'Invalid Request', isset($request['id']) ? $request['id'] : null);
        }
        
        if (isset($request['params']) && !is_array($request['params'])) {
            return $this->errorResponse(-32602, 'Invalid params', isset($request['id']) ? $request['id'] : null);
        }

        $method = isset($request['method']) && is_string($request['method']) ? $request['method'] : '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? null;
        
        try {
            switch ($method) {
                case 'initialize':
                    return $this->initialize($params, $id);
                    
                case 'notifications/initialized':
                case 'initialized':
                    return $this->initialized();

                case 'tools/list':
                    return $this->listTools($params, $id);

                case 'tools/call':
                    return $this->callTool($params, $id);

                case 'resources/list':
                    return $this->listResources($params, $id);

                case 'resources/templates/list':
                    return $this->listResourceTemplates($params, $id);

                case 'resources/read':
                    return $this->readResource($params, $id);
                
                case 'ListOfferings':
                case 'listOfferings':
                    return $this->listOfferings($id);
                
                case 'ping':
                    return $this->ping($id);
                    
                default:
                    return $this->errorResponse(-32601, 'Method not found: ' . $method, $id);
            }
        } catch (Throwable $exception) {
            $this->logThrowable('Unhandled MCP request failure', $exception);
            return $this->errorResponse(-32603, 'Internal error', $id);
        }
    }
    
    /**
     * Initialize MCP server
     */
    private function initialize(array $params, int|string|null $id): array
    {
        try {
            $requestedVersion = isset($params['protocolVersion']) && is_string($params['protocolVersion'])
                ? $params['protocolVersion']
                : null;

            $negotiatedVersion = in_array($requestedVersion, self::SUPPORTED_PROTOCOL_VERSIONS, true)
                ? $requestedVersion
                : self::LATEST_PROTOCOL_VERSION;

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'protocolVersion' => $negotiatedVersion,
                    'capabilities' => [
                        'tools' => [
                            'listChanged' => false,
                        ],
                        'resources' => [
                            'listChanged' => false,
                        ],
                    ],
                    'serverInfo' => [
                        'name' => 'kanboard-mcp',
                        'title' => 'Kanboard MCP Server',
                        'version' => '1.2.0',
                        'description' => 'Kanboard project-management tools and resources via MCP.',
                    ],
                    'instructions' => 'Use the available Kanboard tools to manage projects, tasks, columns, categories, and swimlanes.',
                ]
            ];
        } catch (Throwable $exception) {
            $this->logThrowable('Initialize failed', $exception);
            return $this->errorResponse(-32603, 'Initialize failed', $id);
        }
    }
    
    /**
     * Handle initialized notification
     */
    private function initialized(): ?array
    {
        // This is a notification, so we don't return a response
        return null;
    }
    
    /**
     * List available tools
     */
    private function listTools(array $params, int|string|null $id): array
    {
        $cursor = $params['cursor'] ?? null;
        if ($cursor !== null && !is_string($cursor)) {
            return $this->errorResponse(-32602, 'Invalid params: cursor must be a string', $id);
        }

        $tools = [
            [
                'name' => 'get_projects',
                'description' => 'Get all projects',
                'inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                ]
            ],
            [
                'name' => 'create_project',
                'description' => 'Create a new project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Project name'],
                        'description' => ['type' => 'string', 'description' => 'Project description'],
                        'owner_id' => ['type' => 'integer', 'description' => 'Kanboard user ID to set as project owner and project manager']
                    ],
                    'required' => ['name']
                ]
            ],
            [
                'name' => 'get_tasks',
                'description' => 'Get tasks from a project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'status_id' => [
                            'type' => 'integer',
                            'description' => 'Status ID (1 for active, 0 for archived)',
                            'enum' => [TaskModel::STATUS_CLOSED, TaskModel::STATUS_OPEN],
                            'default' => TaskModel::STATUS_OPEN
                        ]
                    ],
                    'required' => ['project_id']
                ]
            ],
            [
                'name' => 'create_task',
                'description' => 'Create a new task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'title' => ['type' => 'string', 'description' => 'Task title'],
                        'description' => ['type' => 'string', 'description' => 'Task description'],
                        'column_id' => ['type' => 'integer', 'description' => 'Column ID']
                    ],
                    'required' => ['project_id', 'title']
                ]
            ],
            [
                'name' => 'update_task',
                'description' => 'Update an existing task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'title' => ['type' => 'string', 'description' => 'Task title'],
                        'description' => ['type' => 'string', 'description' => 'Task description'],
                        'column_id' => ['type' => 'integer', 'description' => 'Column ID']
                    ],
                    'required' => ['task_id']
                ]
            ],
            [
                'name' => 'get_columns',
                'description' => 'Get columns for a project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID']
                    ],
                    'required' => ['project_id']
                ]
            ],
            [
                'name' => 'move_task',
                'description' => 'Move a task to a different column',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'column_id' => ['type' => 'integer', 'description' => 'Target column ID']
                    ],
                    'required' => ['project_id', 'task_id', 'column_id']
                ]
            ],
            [
                'name' => 'get_task_details',
                'description' => 'Get detailed information about a specific task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID']
                    ],
                    'required' => ['task_id']
                ]
            ],
            [
                'name' => 'delete_task',
                'description' => 'Delete a task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID']
                    ],
                    'required' => ['task_id']
                ]
            ],
            [
                'name' => 'assign_task',
                'description' => 'Assign a task to a user',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'user_id' => ['type' => 'integer', 'description' => 'User ID']
                    ],
                    'required' => ['task_id', 'user_id']
                ]
            ],
            [
                'name' => 'set_task_due_date',
                'description' => 'Set due date for a task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'due_date' => ['type' => 'string', 'description' => 'Due date in YYYY-MM-DD format']
                    ],
                    'required' => ['task_id', 'due_date']
                ]
            ],
            [
                'name' => 'add_task_comment',
                'description' => 'Add a comment to a task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'comment' => ['type' => 'string', 'description' => 'Comment text']
                    ],
                    'required' => ['task_id', 'comment']
                ]
            ],
            [
                'name' => 'get_users',
                'description' => 'Get all users in the system',
                'inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                ]
            ],
            [
                'name' => 'get_task_comments',
                'description' => 'Get all comments for a task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID']
                    ],
                    'required' => ['task_id']
                ]
            ],
            // Administrative Tools - Column Management
            [
                'name' => 'create_column',
                'description' => 'Add new columns to projects',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'title' => ['type' => 'string', 'description' => 'Column title'],
                        'task_limit' => ['type' => 'integer', 'description' => 'Task limit (0 for unlimited)'],
                        'description' => ['type' => 'string', 'description' => 'Column description']
                    ],
                    'required' => ['project_id', 'title']
                ]
            ],
            [
                'name' => 'update_column',
                'description' => 'Modify column settings',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'column_id' => ['type' => 'integer', 'description' => 'Column ID'],
                        'title' => ['type' => 'string', 'description' => 'Column title'],
                        'task_limit' => ['type' => 'integer', 'description' => 'Task limit (0 for unlimited)'],
                        'description' => ['type' => 'string', 'description' => 'Column description'],
                        'hide_in_dashboard' => ['type' => 'integer', 'description' => '1 to hide from dashboard, 0 to show']
                    ],
                    'required' => ['column_id']
                ]
            ],
            [
                'name' => 'delete_column',
                'description' => 'Remove columns',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'column_id' => ['type' => 'integer', 'description' => 'Column ID']
                    ],
                    'required' => ['column_id']
                ]
            ],
            [
                'name' => 'reorder_columns',
                'description' => 'Change column positions',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'column_ids' => ['type' => 'array', 'description' => 'Array of column IDs in desired order']
                    ],
                    'required' => ['project_id', 'column_ids']
                ]
            ],
            // Administrative Tools - Category Management
            [
                'name' => 'create_category',
                'description' => 'Add task categories',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'name' => ['type' => 'string', 'description' => 'Category name'],
                        'description' => ['type' => 'string', 'description' => 'Category description']
                    ],
                    'required' => ['project_id', 'name']
                ]
            ],
            [
                'name' => 'update_category',
                'description' => 'Modify categories',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'category_id' => ['type' => 'integer', 'description' => 'Category ID'],
                        'name' => ['type' => 'string', 'description' => 'Category name'],
                        'description' => ['type' => 'string', 'description' => 'Category description']
                    ],
                    'required' => ['category_id']
                ]
            ],
            [
                'name' => 'delete_category',
                'description' => 'Remove categories',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'category_id' => ['type' => 'integer', 'description' => 'Category ID']
                    ],
                    'required' => ['category_id']
                ]
            ],
            [
                'name' => 'get_categories',
                'description' => 'List project categories',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID']
                    ],
                    'required' => ['project_id']
                ]
            ],
            // Administrative Tools - Swimlane Management
            [
                'name' => 'create_swimlane',
                'description' => 'Add swimlanes',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'name' => ['type' => 'string', 'description' => 'Swimlane name'],
                        'description' => ['type' => 'string', 'description' => 'Swimlane description']
                    ],
                    'required' => ['project_id', 'name']
                ]
            ],
            [
                'name' => 'update_swimlane',
                'description' => 'Modify swimlanes',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'swimlane_id' => ['type' => 'integer', 'description' => 'Swimlane ID'],
                        'name' => ['type' => 'string', 'description' => 'Swimlane name'],
                        'description' => ['type' => 'string', 'description' => 'Swimlane description']
                    ],
                    'required' => ['swimlane_id']
                ]
            ],
            [
                'name' => 'delete_swimlane',
                'description' => 'Remove swimlanes',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'swimlane_id' => ['type' => 'integer', 'description' => 'Swimlane ID']
                    ],
                    'required' => ['project_id', 'swimlane_id']
                ]
            ],
            [
                'name' => 'get_swimlanes',
                'description' => 'List project swimlanes',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID']
                    ],
                    'required' => ['project_id']
                ]
            ]
        ];
        
        $tools = array_merge($tools, [
            [
                'name' => 'get_project',
                'description' => 'Get one project by ID',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'update_project',
                'description' => 'Update project name, description, or owner',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'name' => ['type' => 'string', 'description' => 'Project name'],
                        'description' => ['type' => 'string', 'description' => 'Project description'],
                        'owner_id' => ['type' => 'integer', 'description' => 'Kanboard user ID to set as owner'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'enable_project',
                'description' => 'Enable an inactive project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'disable_project',
                'description' => 'Disable/archive an active project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'get_project_users',
                'description' => 'List users directly assigned to a project and their project roles',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'add_project_user',
                'description' => 'Grant a user access to a project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'user_id' => ['type' => 'integer', 'description' => 'User ID'],
                        'role' => [
                            'type' => 'string',
                            'description' => 'Project role',
                            'enum' => [Role::PROJECT_MANAGER, Role::PROJECT_MEMBER, Role::PROJECT_VIEWER],
                            'default' => Role::PROJECT_MEMBER,
                        ],
                    ],
                    'required' => ['project_id', 'user_id'],
                ],
            ],
            [
                'name' => 'change_project_user_role',
                'description' => 'Change an existing project user role',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'user_id' => ['type' => 'integer', 'description' => 'User ID'],
                        'role' => [
                            'type' => 'string',
                            'description' => 'Project role',
                            'enum' => [Role::PROJECT_MANAGER, Role::PROJECT_MEMBER, Role::PROJECT_VIEWER],
                        ],
                    ],
                    'required' => ['project_id', 'user_id', 'role'],
                ],
            ],
            [
                'name' => 'remove_project_user',
                'description' => 'Remove a user from a project',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'user_id' => ['type' => 'integer', 'description' => 'User ID'],
                    ],
                    'required' => ['project_id', 'user_id'],
                ],
            ],
            [
                'name' => 'close_task',
                'description' => 'Close a task without deleting it',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                    ],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name' => 'reopen_task',
                'description' => 'Reopen a closed task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                    ],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name' => 'duplicate_project',
                'description' => 'Duplicate a project structure and optionally its permissions, categories, actions, tags, filters, metadata, and tasks',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Source project ID'],
                        'owner_id' => ['type' => 'integer', 'description' => 'Owner/user ID for the duplicated project'],
                        'name' => ['type' => 'string', 'description' => 'Optional name for the duplicated project'],
                        'include_tasks' => ['type' => 'boolean', 'description' => 'Copy tasks as well as project structure', 'default' => false],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'delete_project',
                'description' => 'Permanently delete a project. Requires confirm=true.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => ['type' => 'integer', 'description' => 'Project ID'],
                        'confirm' => ['type' => 'boolean', 'description' => 'Must be true to permanently delete the project'],
                    ],
                    'required' => ['project_id', 'confirm'],
                ],
            ],
            [
                'name' => 'get_subtasks',
                'description' => 'List subtasks for a task',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                    ],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name' => 'create_subtask',
                'description' => 'Create a subtask',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'Task ID'],
                        'title' => ['type' => 'string', 'description' => 'Subtask title'],
                        'user_id' => ['type' => 'integer', 'description' => 'Optional assignee user ID'],
                        'status' => [
                            'type' => 'integer',
                            'description' => '0 Todo, 1 In progress, 2 Done',
                            'enum' => [SubtaskModel::STATUS_TODO, SubtaskModel::STATUS_INPROGRESS, SubtaskModel::STATUS_DONE],
                        ],
                    ],
                    'required' => ['task_id', 'title'],
                ],
            ],
            [
                'name' => 'update_subtask',
                'description' => 'Update a subtask title, assignee, or status',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'subtask_id' => ['type' => 'integer', 'description' => 'Subtask ID'],
                        'title' => ['type' => 'string', 'description' => 'Subtask title'],
                        'user_id' => ['type' => 'integer', 'description' => 'Assignee user ID, 0 for unassigned'],
                        'status' => [
                            'type' => 'integer',
                            'description' => '0 Todo, 1 In progress, 2 Done',
                            'enum' => [SubtaskModel::STATUS_TODO, SubtaskModel::STATUS_INPROGRESS, SubtaskModel::STATUS_DONE],
                        ],
                    ],
                    'required' => ['subtask_id'],
                ],
            ],
            [
                'name' => 'delete_subtask',
                'description' => 'Permanently delete a subtask. Requires confirm=true.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'subtask_id' => ['type' => 'integer', 'description' => 'Subtask ID'],
                        'confirm' => ['type' => 'boolean', 'description' => 'Must be true to permanently delete the subtask'],
                    ],
                    'required' => ['subtask_id', 'confirm'],
                ],
            ],
            [
                'name' => 'get_custom_css',
                'description' => 'Get the active Customizer theme CSS and current theme selection',
                'inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'set_custom_css',
                'description' => 'Set the Hallman Customizer CSS theme and activate it globally',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'css' => ['type' => 'string', 'description' => 'Complete CSS stylesheet to save as the Hallman theme'],
                    ],
                    'required' => ['css'],
                ],
            ],
        ]);

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'tools' => $tools
            ]
        ];
    }
    
    /**
     * Call a tool
     */
    private function callTool(array $params, int|string|null $id): array
    {
        if (!isset($params['name']) || !is_string($params['name']) || trim($params['name']) === '') {
            return $this->errorResponse(-32602, 'Invalid params: name is required and must be a non-empty string', $id);
        }

        if (array_key_exists('arguments', $params) && !is_array($params['arguments'])) {
            return $this->errorResponse(-32602, 'Invalid params: arguments must be an object', $id);
        }

        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        try {
            $result = null;
            
            switch ($toolName) {
                case 'get_projects':
                    $projects = $this->container['projectModel']->getAll();
                    $result = array_values($projects);
                    break;
                    
                case 'create_project':
                    if (!isset($arguments['name']) || !is_string($arguments['name']) || trim($arguments['name']) === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: name must be a non-empty string', $id);
                    }

                    $ownerId = isset($arguments['owner_id']) ? (int) $arguments['owner_id'] : 0;
                    if ($ownerId < 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: owner_id must be zero or a positive integer', $id);
                    }

                    $projectId = $this->container['projectModel']->create(
                        [
                            'name' => trim($arguments['name']),
                            'description' => $arguments['description'] ?? ''
                        ],
                        $ownerId,
                        $ownerId > 0
                    );
                    $result = ['project_id' => $projectId];
                    break;

                case 'get_project':
                    $projectId = isset($arguments['project_id']) ? (int) $arguments['project_id'] : 0;
                    if ($projectId <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }
                    $result = $this->container['projectModel']->getById($projectId);
                    break;

                case 'update_project':
                    $projectId = isset($arguments['project_id']) ? (int) $arguments['project_id'] : 0;
                    if ($projectId <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }

                    $project = $this->container['projectModel']->getById($projectId);
                    if (empty($project)) {
                        return $this->createToolExecutionErrorResponse('Project not found', $id);
                    }

                    $update = [
                        'id' => $projectId,
                        'per_swimlane_task_limits' => (int) ($project['per_swimlane_task_limits'] ?? 0),
                    ];
                    if (isset($arguments['name'])) $update['name'] = trim((string) $arguments['name']);
                    if (array_key_exists('description', $arguments)) $update['description'] = (string) $arguments['description'];
                    if (isset($arguments['owner_id'])) $update['owner_id'] = (int) $arguments['owner_id'];

                    $updated = $this->container['projectModel']->update($update);

                    if ($updated && isset($arguments['owner_id']) && (int) $arguments['owner_id'] > 0) {
                        $ownerId = (int) $arguments['owner_id'];
                        $currentRole = $this->container['projectUserRoleModel']->getUserRole($projectId, $ownerId);
                        if (empty($currentRole)) {
                            $this->container['projectUserRoleModel']->addUser($projectId, $ownerId, Role::PROJECT_MANAGER);
                        } elseif ($currentRole !== Role::PROJECT_MANAGER) {
                            $this->container['projectUserRoleModel']->changeUserRole($projectId, $ownerId, Role::PROJECT_MANAGER);
                        }
                    }

                    $result = ['success' => $updated];
                    break;

                case 'enable_project':
                case 'disable_project':
                    $projectId = isset($arguments['project_id']) ? (int) $arguments['project_id'] : 0;
                    if ($projectId <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }
                    $project = $this->container['projectModel']->getById($projectId);
                    if (empty($project)) {
                        return $this->createToolExecutionErrorResponse('Project not found', $id);
                    }
                    $projectStatus = $toolName === 'enable_project' ? ProjectModel::ACTIVE : ProjectModel::INACTIVE;
                    $updated = $this->container['projectModel']->update([
                        'id' => $projectId,
                        'is_active' => $projectStatus,
                        'per_swimlane_task_limits' => (int) ($project['per_swimlane_task_limits'] ?? 0),
                    ]);
                    $result = ['success' => $updated, 'is_active' => $projectStatus];
                    break;

                case 'duplicate_project':
                    $projectId = isset($arguments['project_id']) ? (int) $arguments['project_id'] : 0;
                    $ownerId = isset($arguments['owner_id']) ? (int) $arguments['owner_id'] : 0;
                    if ($projectId <= 0 || $ownerId < 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be positive and owner_id must be zero or positive', $id);
                    }

                    $selection = [
                        'projectPermissionModel',
                        'categoryModel',
                        'actionModel',
                        'tagDuplicationModel',
                        'customFilterModel',
                        'projectMetadataModel',
                    ];
                    if (($arguments['include_tasks'] ?? false) === true) {
                        $selection[] = 'projectTaskDuplicationModel';
                    }

                    $newProjectId = $this->container['projectDuplicationModel']->duplicate(
                        $projectId,
                        $selection,
                        $ownerId,
                        isset($arguments['name']) ? trim((string) $arguments['name']) : null
                    );
                    $result = ['project_id' => $newProjectId];
                    break;

                case 'delete_project':
                    $projectId = isset($arguments['project_id']) ? (int) $arguments['project_id'] : 0;
                    if ($projectId <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }
                    if (($arguments['confirm'] ?? false) !== true) {
                        return $this->createToolExecutionErrorResponse('Destructive action requires confirm=true', $id);
                    }
                    $result = ['success' => $this->container['projectModel']->remove($projectId)];
                    break;

                case 'get_project_users':
                    $projectId = isset($arguments['project_id']) ? (int) $arguments['project_id'] : 0;
                    if ($projectId <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }
                    $result = array_values($this->container['projectUserRoleModel']->getUsers($projectId));
                    break;

                case 'add_project_user':
                case 'change_project_user_role':
                    $projectId = isset($arguments['project_id']) ? (int) $arguments['project_id'] : 0;
                    $userId = isset($arguments['user_id']) ? (int) $arguments['user_id'] : 0;
                    $role = $arguments['role'] ?? Role::PROJECT_MEMBER;
                    $validRoles = [Role::PROJECT_MANAGER, Role::PROJECT_MEMBER, Role::PROJECT_VIEWER];

                    if ($projectId <= 0 || $userId <= 0 || !in_array($role, $validRoles, true)) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id/user_id must be positive integers and role must be a valid Kanboard project role', $id);
                    }

                    $existingRole = $this->container['projectUserRoleModel']->getUserRole($projectId, $userId);
                    if ($toolName === 'change_project_user_role' && empty($existingRole)) {
                        return $this->createToolExecutionErrorResponse('User is not currently a member of this project', $id);
                    }

                    if (empty($existingRole)) {
                        $changed = $this->container['projectUserRoleModel']->addUser($projectId, $userId, $role);
                    } else {
                        $changed = $this->container['projectUserRoleModel']->changeUserRole($projectId, $userId, $role);
                    }

                    $result = ['success' => $changed, 'role' => $role];
                    break;

                case 'remove_project_user':
                    $projectId = isset($arguments['project_id']) ? (int) $arguments['project_id'] : 0;
                    $userId = isset($arguments['user_id']) ? (int) $arguments['user_id'] : 0;
                    if ($projectId <= 0 || $userId <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id and user_id must be positive integers', $id);
                    }
                    $result = ['success' => $this->container['projectUserRoleModel']->removeUser($projectId, $userId)];
                    break;
                    
                case 'get_tasks':
                    $projectId = isset($arguments['project_id']) ? (int) $arguments['project_id'] : null;
                    $statusId = array_key_exists('status_id', $arguments)
                        ? (int) $arguments['status_id']
                        : TaskModel::STATUS_OPEN;

                    if ($projectId === null || $projectId <= 0 || !in_array(
                        $statusId,
                        [TaskModel::STATUS_OPEN, TaskModel::STATUS_CLOSED],
                        true
                    )) {
                        return $this->createToolExecutionErrorResponse(
                            'Invalid arguments: project_id and status_id must be integers (status_id in [0,1])',
                            $id
                        );
                    }

                    try {
                        $tasks = $this->container['taskFinderModel']->getAll($projectId, $statusId);
                        $result = array_values($tasks);
                    } catch (Throwable $exception) {
                        $this->logThrowable('Failed to get tasks', $exception);
                        return $this->createToolExecutionErrorResponse('Failed to get tasks', $id);
                    }
                    break;

                case 'create_task':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }

                    if (!isset($arguments['title']) || !is_string($arguments['title']) || trim($arguments['title']) === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: title must be a non-empty string', $id);
                    }

                    $taskData = [
                        'project_id' => (int) $arguments['project_id'],
                        'title' => trim($arguments['title']),
                        'description' => $arguments['description'] ?? ''
                    ];
                    if (isset($arguments['column_id'])) {
                        $taskData['column_id'] = (int) $arguments['column_id'];
                    }
                    $taskId = $this->container['taskCreationModel']->create($taskData);
                    $result = ['task_id' => $taskId];
                    break;

                case 'update_task':
                    if (!isset($arguments['task_id']) || (int) $arguments['task_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                    }

                    $taskData = ['id' => $arguments['task_id']];
                    if (isset($arguments['title'])) $taskData['title'] = $arguments['title'];
                    if (isset($arguments['description'])) $taskData['description'] = $arguments['description'];
                    if (isset($arguments['column_id'])) $taskData['column_id'] = $arguments['column_id'];
                    
                    $updateResult = $this->container['taskModificationModel']->update($taskData);
                    $result = ['success' => $updateResult];
                    break;
                    
                case 'get_columns':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }

                    $columns = $this->container['columnModel']->getAll($arguments['project_id']);
                    $result = array_values($columns);
                    break;
                    
                case 'move_task':
                    $projectId = isset($arguments['project_id']) ? (int) $arguments['project_id'] : 0;
                    $taskId = isset($arguments['task_id']) ? (int) $arguments['task_id'] : 0;
                    $columnId = isset($arguments['column_id']) ? (int) $arguments['column_id'] : 0;

                    if ($projectId <= 0 || $taskId <= 0 || $columnId <= 0) {
                        return $this->createToolExecutionErrorResponse(
                            'Invalid arguments: project_id, task_id, and column_id must be positive integers',
                            $id
                        );
                    }

                    $moveResult = $this->container['taskPositionModel']->movePosition(
                        $projectId,
                        $taskId,
                        $columnId,
                        1
                    );
                    $result = ['success' => $moveResult];
                    break;
                    
                case 'get_task_details':
                    if (!isset($arguments['task_id']) || (int) $arguments['task_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                    }
                    
                    $task = $this->container['taskFinderModel']->getById($arguments['task_id']);
                    $result = $task;
                    break;

                case 'close_task':
                case 'reopen_task':
                    $taskId = isset($arguments['task_id']) ? (int) $arguments['task_id'] : 0;
                    if ($taskId <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                    }
                    $changed = $toolName === 'close_task'
                        ? $this->container['taskStatusModel']->close($taskId)
                        : $this->container['taskStatusModel']->open($taskId);
                    $result = ['success' => $changed];
                    break;

                case 'delete_task':
                    if (!isset($arguments['task_id']) || (int) $arguments['task_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                    }

                    $deleteResult = $this->container['taskModel']->remove($arguments['task_id']);
                    $result = ['success' => $deleteResult];
                    break;

                case 'assign_task':
                    if (!isset($arguments['task_id']) || (int) $arguments['task_id'] <= 0 || !isset($arguments['user_id']) || (int) $arguments['user_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id and user_id must be positive integers', $id);
                    }

                    $assignResult = $this->container['taskModificationModel']->update([
                        'id' => $arguments['task_id'],
                        'owner_id' => $arguments['user_id']
                    ]);
                    $result = ['success' => $assignResult];
                    break;
                    
                case 'set_task_due_date':
                    if (!isset($arguments['task_id']) || (int) $arguments['task_id'] <= 0 || !isset($arguments['due_date']) || !is_string($arguments['due_date']) || trim($arguments['due_date']) === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer and due_date must be a non-empty string', $id);
                    }

                    $dueDateResult = $this->container['taskModificationModel']->update([
                        'id' => $arguments['task_id'],
                        'date_due' => $arguments['due_date']
                    ]);
                    $result = ['success' => $dueDateResult];
                    break;
                    
                case 'add_task_comment':
                    if (!isset($arguments['task_id']) || (int) $arguments['task_id'] <= 0 || !isset($arguments['comment']) || !is_string($arguments['comment']) || trim($arguments['comment']) === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer and comment must be a non-empty string', $id);
                    }

                    $commentId = $this->container['commentModel']->create([
                        'task_id' => $arguments['task_id'],
                        'comment' => $arguments['comment'],
                        'user_id' => isset($arguments['user_id']) ? (int) $arguments['user_id'] : 0
                    ]);
                    $result = ['comment_id' => $commentId];
                    break;
                    
                case 'get_users':
                    $users = $this->container['userModel']->getAll();
                    $result = array_values(array_map(static function (array $user): array {
                        return [
                            'id' => (int) $user['id'],
                            'username' => $user['username'] ?? '',
                            'name' => $user['name'] ?? null,
                            'email' => $user['email'] ?? null,
                            'role' => $user['role'] ?? null,
                            'is_active' => isset($user['is_active']) ? (int) $user['is_active'] : 0,
                            'timezone' => $user['timezone'] ?? null,
                            'language' => $user['language'] ?? null,
                            'avatar_path' => $user['avatar_path'] ?? null,
                        ];
                    }, $users));
                    break;
                    
                case 'get_task_comments':
                    if (!isset($arguments['task_id']) || (int) $arguments['task_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                    }

                    $comments = $this->container['commentModel']->getAll($arguments['task_id']);
                    $result = array_values($comments);
                    break;
                    
                // Hallman additions - Subtask Management
                case 'get_subtasks':
                    $taskId = isset($arguments['task_id']) ? (int) $arguments['task_id'] : 0;
                    if ($taskId <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be a positive integer', $id);
                    }
                    $result = array_values($this->container['subtaskModel']->getAll($taskId));
                    break;

                case 'create_subtask':
                    $taskId = isset($arguments['task_id']) ? (int) $arguments['task_id'] : 0;
                    $title = isset($arguments['title']) ? trim((string) $arguments['title']) : '';
                    if ($taskId <= 0 || $title === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: task_id must be positive and title must be non-empty', $id);
                    }

                    $subtaskData = [
                        'task_id' => $taskId,
                        'title' => $title,
                    ];
                    if (isset($arguments['user_id'])) $subtaskData['user_id'] = (int) $arguments['user_id'];
                    if (isset($arguments['status'])) $subtaskData['status'] = (int) $arguments['status'];

                    $subtaskId = $this->container['subtaskModel']->create($subtaskData);
                    $result = ['subtask_id' => $subtaskId];
                    break;

                case 'update_subtask':
                    $subtaskId = isset($arguments['subtask_id']) ? (int) $arguments['subtask_id'] : 0;
                    if ($subtaskId <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: subtask_id must be a positive integer', $id);
                    }

                    $subtask = $this->container['subtaskModel']->getById($subtaskId);
                    if (empty($subtask)) {
                        return $this->createToolExecutionErrorResponse('Subtask not found', $id);
                    }

                    $values = [
                        'id' => $subtaskId,
                        'task_id' => (int) $subtask['task_id'],
                        'title' => $subtask['title'],
                        'user_id' => (int) $subtask['user_id'],
                        'status' => (int) $subtask['status'],
                        'time_estimated' => (int) $subtask['time_estimated'],
                        'time_spent' => (int) $subtask['time_spent'],
                    ];
                    if (isset($arguments['title'])) $values['title'] = trim((string) $arguments['title']);
                    if (isset($arguments['user_id'])) $values['user_id'] = (int) $arguments['user_id'];
                    if (isset($arguments['status'])) $values['status'] = (int) $arguments['status'];

                    $result = ['success' => $this->container['subtaskModel']->update($values)];
                    break;

                case 'delete_subtask':
                    $subtaskId = isset($arguments['subtask_id']) ? (int) $arguments['subtask_id'] : 0;
                    if ($subtaskId <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: subtask_id must be a positive integer', $id);
                    }
                    if (($arguments['confirm'] ?? false) !== true) {
                        return $this->createToolExecutionErrorResponse('Destructive action requires confirm=true', $id);
                    }
                    $result = ['success' => $this->container['subtaskModel']->remove($subtaskId)];
                    break;

                // Hallman additions - Customizer CSS
                case 'get_custom_css':
                    $pluginFolder = basename(PLUGINS_DIR);
                    $themeSelection = $this->container['configModel']->get(
                        'themeSelection',
                        $pluginFolder . '/Customizer/Assets/css/theme.css'
                    );

                    $hallmanSource = DATA_DIR . '/files/customizer/themes/Hallman.css';
                    $selectedCss = '';
                    $source = null;

                    if (file_exists($hallmanSource)) {
                        $selectedCss = (string) file_get_contents($hallmanSource);
                        $source = $hallmanSource;
                    } else {
                        $candidate = dirname(__DIR__, 2) . '/' . ltrim($themeSelection, '/');
                        if (file_exists($candidate)) {
                            $selectedCss = (string) file_get_contents($candidate);
                            $source = $candidate;
                        }
                    }

                    $result = [
                        'theme_selection' => $themeSelection,
                        'source' => $source,
                        'css' => $selectedCss,
                    ];
                    break;

                case 'set_custom_css':
                    if (!isset($arguments['css']) || !is_string($arguments['css'])) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: css must be a string', $id);
                    }

                    $themeDir = DATA_DIR . '/files/customizer/themes';
                    if (!is_dir($themeDir) && !mkdir($themeDir, 0755, true) && !is_dir($themeDir)) {
                        return $this->createToolExecutionErrorResponse('Unable to create Customizer theme directory', $id);
                    }

                    $themeFile = $themeDir . '/Hallman.css';
                    $bytes = file_put_contents($themeFile, $arguments['css']);
                    if ($bytes === false) {
                        return $this->createToolExecutionErrorResponse('Unable to write Hallman.css', $id);
                    }

                    $pluginFolder = basename(PLUGINS_DIR);
                    $themeSelection = $pluginFolder . '/Customizer/Assets/css/userthemes/Hallman.css';
                    $saved = $this->container['configModel']->save([
                        'themeSelection' => $themeSelection,
                    ]);

                    $result = [
                        'success' => (bool) $saved,
                        'bytes_written' => $bytes,
                        'theme_selection' => $themeSelection,
                    ];
                    break;

                // Administrative Tools - Column Management
                case 'create_column':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0 || !isset($arguments['title']) || !is_string($arguments['title']) || trim($arguments['title']) === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer and title must be a non-empty string', $id);
                    }

                    $columnId = $this->container['columnModel']->create(
                        $arguments['project_id'],
                        trim($arguments['title']),
                        $arguments['task_limit'] ?? 0,
                        $arguments['description'] ?? ''
                    );
                    $result = ['column_id' => $columnId];
                    break;
                    
                case 'update_column':
                    if (!isset($arguments['column_id']) || (int) $arguments['column_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: column_id must be a positive integer', $id);
                    }

                    $columnId = (int) $arguments['column_id'];
                    $column = $this->container['columnModel']->getById($columnId);
                    if (empty($column)) {
                        return $this->createToolExecutionErrorResponse('Column not found', $id);
                    }
                    $title = $arguments['title'] ?? $column['title'];
                    $taskLimit = isset($arguments['task_limit']) ? (int) $arguments['task_limit'] : (int) $column['task_limit'];
                    $description = $arguments['description'] ?? $column['description'];
                    $hideInDashboard = isset($arguments['hide_in_dashboard'])
                        ? (int) $arguments['hide_in_dashboard']
                        : (int) ($column['hide_in_dashboard'] ?? 0);
                    $updateResult = $this->container['columnModel']->update($columnId, $title, $taskLimit, $description, $hideInDashboard);
                    $result = ['success' => $updateResult];
                    break;
                    
                case 'delete_column':
                    if (!isset($arguments['column_id']) || (int) $arguments['column_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: column_id must be a positive integer', $id);
                    }

                    $deleteResult = $this->container['columnModel']->remove($arguments['column_id']);
                    $result = ['success' => $deleteResult];
                    break;
                    
                case 'reorder_columns':
                    return $this->handleReorderColumns($arguments, $id);
                    
                // Administrative Tools - Category Management
                case 'create_category':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0 || !isset($arguments['name']) || !is_string($arguments['name']) || trim($arguments['name']) === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer and name must be a non-empty string', $id);
                    }

                    $categoryId = $this->container['categoryModel']->create([
                        'project_id' => $arguments['project_id'],
                        'name' => trim($arguments['name']),
                        'description' => $arguments['description'] ?? ''
                    ]);
                    $result = ['category_id' => $categoryId];
                    break;

                case 'update_category':
                    if (!isset($arguments['category_id']) || (int) $arguments['category_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: category_id must be a positive integer', $id);
                    }

                    $updateData = ['id' => $arguments['category_id']];
                    if (isset($arguments['name'])) $updateData['name'] = $arguments['name'];
                    if (isset($arguments['description'])) $updateData['description'] = $arguments['description'];
                    $updateResult = $this->container['categoryModel']->update($updateData);
                    $result = ['success' => $updateResult];
                    break;
                    
                case 'delete_category':
                    if (!isset($arguments['category_id']) || (int) $arguments['category_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: category_id must be a positive integer', $id);
                    }
                    
                    $deleteResult = $this->container['categoryModel']->remove($arguments['category_id']);
                    $result = ['success' => $deleteResult];
                    break;

                case 'get_categories':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }

                    $categories = $this->container['categoryModel']->getAll($arguments['project_id']);
                    $result = array_values($categories);
                    break;
                    
                // Administrative Tools - Swimlane Management
                case 'create_swimlane':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0 || !isset($arguments['name']) || !is_string($arguments['name']) || trim($arguments['name']) === '') {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer and name must be a non-empty string', $id);
                    }

                    $swimlaneId = $this->container['swimlaneModel']->create(
                        $arguments['project_id'],
                        trim($arguments['name']),
                        $arguments['description'] ?? ''
                    );
                    $result = ['swimlane_id' => $swimlaneId];
                    break;

                case 'update_swimlane':
                    if (!isset($arguments['swimlane_id']) || (int) $arguments['swimlane_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: swimlane_id must be a positive integer', $id);
                    }

                    $updateData = [];
                    if (isset($arguments['name'])) $updateData['name'] = $arguments['name'];
                    if (isset($arguments['description'])) $updateData['description'] = $arguments['description'];
                    $updateResult = $this->container['swimlaneModel']->update($arguments['swimlane_id'], $updateData);
                    $result = ['success' => $updateResult];
                    break;
                    
                case 'delete_swimlane':
                    $projectId = isset($arguments['project_id']) ? (int) $arguments['project_id'] : 0;
                    $swimlaneId = isset($arguments['swimlane_id']) ? (int) $arguments['swimlane_id'] : 0;

                    if ($projectId <= 0 || $swimlaneId <= 0) {
                        return $this->createToolExecutionErrorResponse(
                            'Invalid arguments: project_id and swimlane_id must be positive integers',
                            $id
                        );
                    }

                    $deleteResult = $this->container['swimlaneModel']->remove($projectId, $swimlaneId);
                    $result = ['success' => $deleteResult];
                    break;
                    
                case 'get_swimlanes':
                    if (!isset($arguments['project_id']) || (int) $arguments['project_id'] <= 0) {
                        return $this->createToolExecutionErrorResponse('Invalid arguments: project_id must be a positive integer', $id);
                    }

                    $swimlanes = $this->container['swimlaneModel']->getAll($arguments['project_id']);
                    $result = array_values($swimlanes);
                    break;
                    
                default:
                    return $this->errorResponse(-32602, 'Unknown tool: ' . $toolName, $id);
            }
            
            return $this->createSuccessResponse($result, $id);
            
        } catch (InvalidArgumentException $exception) {
            return $this->createToolExecutionErrorResponse($exception->getMessage(), $id);
        } catch (Throwable $exception) {
            $this->logThrowable(sprintf('Tool execution failed for %s', $toolName), $exception);
            return $this->createToolExecutionErrorResponse('Tool execution failed', $id);
        }
    }
    
    /**
     * List available resources
     */
    private function listResources(array $params, int|string|null $id): array
    {
        $cursor = $params['cursor'] ?? null;
        if ($cursor !== null && !is_string($cursor)) {
            return $this->errorResponse(-32602, 'Invalid params: cursor must be a string', $id);
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'resources' => [
                    [
                        'uri' => 'kanboard://projects',
                        'name' => 'Project List',
                        'description' => 'List of all projects',
                        'mimeType' => 'application/json'
                    ],
                    [
                        'uri' => 'kanboard://users',
                        'name' => 'User List',
                        'description' => 'List of all users',
                        'mimeType' => 'application/json'
                    ]
                ]
            ]
        ];
    }

    /**
     * List available resource templates.
     */
    private function listResourceTemplates(array $params, int|string|null $id): array
    {
        $cursor = $params['cursor'] ?? null;
        if ($cursor !== null && !is_string($cursor)) {
            return $this->errorResponse(-32602, 'Invalid params: cursor must be a string', $id);
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'resourceTemplates' => [],
            ],
        ];
    }
    
    /**
     * Read a resource
     */
    private function readResource(array $params, int|string|null $id): array
    {
        $uri = $params['uri'] ?? '';
        
        try {
            switch ($uri) {
                case 'kanboard://projects':
                    $projects = $this->container['projectModel']->getAll();
                    $content = json_encode(array_values($projects), JSON_PRETTY_PRINT);
                    break;
                    
                case 'kanboard://users':
                    $users = $this->container['userModel']->getAll();
                    $content = json_encode(array_values($users), JSON_PRETTY_PRINT);
                    break;
                    
                default:
                    return $this->errorResponse(-32002, 'Resource not found', $id, ['uri' => $uri]);
            }
            
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'contents' => [
                        [
                            'uri' => $uri,
                            'mimeType' => 'application/json',
                            'text' => $content
                        ]
                    ]
                ]
            ];
        } catch (Throwable $exception) {
            $this->logThrowable('Resource read failed', $exception);
            return $this->errorResponse(-32603, 'Resource read failed', $id);
        }
    }
    
    /**
     * List offerings (Cursor-specific)
     */
    private function listOfferings(int|string|null $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'offerings' => []
            ]
        ];
    }
    
    /**
     * Handle ping
     */
    private function ping(int|string|null $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => (object)[]
        ];
    }
    
    /**
     * Get tools list for internal use
     */
    private function getToolsList(): array
    {
        // For internal use if needed
        return [];
    }
    
    /**
     * Get resources list for internal use
     */
    private function getResourcesList(): array
    {
        return [
            [
                'uri' => 'kanboard://projects',
                'name' => 'Project List',
                'description' => 'List of all projects',
                'mimeType' => 'application/json'
            ],
            [
                'uri' => 'kanboard://users',
                'name' => 'User List',
                'description' => 'List of all users',
                'mimeType' => 'application/json'
            ]
        ];
    }
    
    /**
     * Create error response
     */
    private function errorResponse(int $code, string $message, int|string|null $id, mixed $data = null): array
    {
        $error = [
            'code' => $code,
            'message' => $message
        ];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ];
    }

    private function createToolExecutionErrorResponse(string $message, int|string|null $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $message,
                    ],
                ],
                'isError' => true,
            ],
        ];
    }

    private function createSuccessResponse(mixed $result, int|string|null $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($result, JSON_PRETTY_PRINT)
                    ]
                ]
            ]
        ];
    }

    /**
     * Canonical implementation of column reordering.
     * Demonstrates a clean control flow: sanitize -> verify -> execute.
     */
    private function handleReorderColumns(array $arguments, int|string|null $id): array
    {
        // 1. Sanitize and validate arguments
        try {
            [$projectId, $columnIds] = $this->sanitizeColumnReorderArguments($arguments);
        } catch (InvalidArgumentException $exception) {
            return $this->createAndLogFailure(
                'Invalid params for reorder_columns',
                $id,
                $exception->getMessage(),
                ['arguments' => $arguments]
            );
        }

        // 2. Verify state by comparing with existing columns
        /** @var ColumnModel $columnModel */
        $columnModel = $this->container['columnModel'];
        $currentIds = $this->getColumnIdsSnapshot($columnModel, $projectId);

        if (($mismatchError = $this->checkColumnMismatch($columnIds, $currentIds)) !== null) {
            return $this->createAndLogFailure(
                'State mismatch for reorder_columns',
                $id,
                $mismatchError,
                ['project_id' => $projectId, 'requested' => $columnIds, 'current' => $currentIds]
            );
        }

        // 3. Execute the reordering
        try {
            foreach ($columnIds as $index => $columnId) {
                $position = $index + 1;
                if ($columnModel->changePosition($projectId, $columnId, $position) !== true) {
                    // This is an exceptional case if the core model rejects the change
                    throw new \RuntimeException("Kanboard core rejected position change for column {$columnId} to {$position}.");
                }
            }
        } catch (Throwable $exception) {
            return $this->createAndLogFailure(
                'Execution failed for reorder_columns',
                $id,
                'Column reorder failed during execution.',
                ['project_id' => $projectId, 'requested' => $columnIds],
                $exception
            );
        }

        // Success
        return $this->createSuccessResponse(['success' => true], $id);
    }

    /**
     * Utility to check for mismatches between requested and current column sets.
     * Returns a formatted error string or null if there is no mismatch.
     */
    private function checkColumnMismatch(array $requested, array $current): ?string
    {
        if (count($requested) !== count($current)) {
            return 'Invalid params: number of columns does not match.';
        }

        $missing = array_values(array_diff($current, $requested));
        $unknown = array_values(array_diff($requested, $current));

        if (empty($missing) && empty($unknown)) {
            return null;
        }

        $details = [];
        if (!empty($missing)) {
            $details[] = 'missing ids [' . implode(', ', $missing) . ']';
        }
        if (!empty($unknown)) {
            $details[] = 'unknown ids [' . implode(', ', $unknown) . ']';
        }

        return 'Invalid params: column sets do not match (' . implode('; ', $details) . ').';
    }

    /**
     * Unified failure point for logging and creating an error response.
     */
    private function createAndLogFailure(
        string $logMessage,
        int|string|null $id,
        string $responseMessage,
        array $context,
        ?Throwable $exception = null
    ): array {
        $logContext = [
            'context' => $context,
            'mcp_channel' => 'reorder_columns_failure'
        ];

        if ($exception !== null) {
            $logContext['exception'] = $exception;
        }

        $this->logError($logMessage, $logContext);

        return $this->createToolExecutionErrorResponse($responseMessage, $id);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{0:int,1:array<int,int>}
     */
    private function sanitizeColumnReorderArguments(array $arguments): array
    {
        if (!array_key_exists('project_id', $arguments)) {
            throw new InvalidArgumentException('Invalid params: project_id is required and must be a positive integer');
        }

        $projectId = $this->filterPositiveInteger($arguments['project_id']);

        if ($projectId === null) {
            throw new InvalidArgumentException('Invalid params: project_id must be a positive integer');
        }

        if (!array_key_exists('column_ids', $arguments)) {
            throw new InvalidArgumentException('Invalid params: column_ids must be a non-empty array of positive integers');
        }

        $columnIdArgument = $arguments['column_ids'];

        if (is_string($columnIdArgument)) {
            $columnIdArgument = array_values(array_filter(
                array_map('trim', explode(',', $columnIdArgument)),
                static fn(string $value): bool => $value !== ''
            ));
        } elseif ($columnIdArgument instanceof Traversable) {
            $columnIdArgument = iterator_to_array($columnIdArgument, false);
        }

        if (!is_array($columnIdArgument) || $columnIdArgument === []) {
            throw new InvalidArgumentException('Invalid params: column_ids must be a non-empty array of positive integers');
        }

        $columnIds = [];

        foreach (array_values($columnIdArgument) as $index => $rawColumnId) {
            $columnId = $this->filterPositiveInteger($rawColumnId);

            if ($columnId === null) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid params: column_ids[%d] must be a positive integer',
                    $index
                ));
            }

            $columnIds[] = $columnId;
        }

        if (count($columnIds) !== count(array_unique($columnIds))) {
            throw new InvalidArgumentException('Invalid params: column_ids must contain unique identifiers');
        }

        return [$projectId, $columnIds];
    }

    /**
     * @param ColumnModel|object $columnModel
     * @return array<int,int>
     */
    private function getColumnIdsSnapshot($columnModel, int $projectId): array
    {
        if (!is_object($columnModel) || !method_exists($columnModel, 'getAll')) {
            return [];
        }

        try {
            $columns = $columnModel->getAll($projectId);
        } catch (Throwable $exception) {
            $this->logThrowable(
                sprintf('Failed to fetch column snapshot for project %d', $projectId),
                $exception
            );

            return [];
        }

        if (!is_array($columns)) {
            return [];
        }

        $filtered = array_filter(
            $columns,
            static fn($column): bool => is_array($column) && isset($column['id']) && (int) $column['id'] > 0
        );

        return array_values(array_map(
            static fn(array $column): int => (int) $column['id'],
            $filtered
        ));
    }

    private function filterPositiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value)) {
            $filtered = filter_var(trim($value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($filtered !== false) {
                return (int) $filtered;
            }

            return null;
        }

        return null;
    }

    private function logError(string $message, array $context = []): void
    {
        if (isset($this->container['logger'])) {
            $this->container['logger']->error($message, $context);
        }
    }

    /**
     * Log throwable details without exposing them to clients
     */
    private function logThrowable(string $message, Throwable $throwable): void
    {
        if (isset($this->container['logger'])) {
            $this->container['logger']->error($message, ['exception' => $throwable]);
        }
    }
}
