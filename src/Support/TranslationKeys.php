<?php

declare(strict_types=1);

namespace AxolotlBucketLabs\DevTools\Support;

/**
 * Canonical keys shared by translation call sites and language catalogs.
 */
final class TranslationKeys
{
	public const BUILD_BOTH_REQUIRED = 'build.both_required';
	public const BUILD_DUAL = 'build.dual';
	public const BUILD_PLUGIN = 'build.plugin';
	public const BUILD_VIRION = 'build.virion';
	public const CLI_ARTIFACT_TYPE_MISMATCH = 'cli.artifact_type_mismatch';
	public const CLI_ARTIFACT_TYPE_MISSING = 'cli.artifact_type_missing';
	public const CLI_BUILD_TYPE_INVALID = 'cli.build_type_invalid';
	public const CLI_COMMAND_REQUIRED = 'cli.command_required';
	public const CLI_CWD_UNAVAILABLE = 'cli.cwd_unavailable';
	public const CLI_DUAL_ARTIFACT_TYPE_REQUIRED = 'cli.dual_artifact_type_required';
	public const CLI_EXTRACT_TYPE_INVALID = 'cli.extract_type_invalid';
	public const CLI_FAILURE_SUGGESTION = 'cli.failure_suggestion';
	public const CLI_FORMAT_INVALID = 'cli.format_invalid';
	public const CLI_HELP = 'cli.help';
	public const CLI_LOCAL_PATH_ONLY = 'cli.local_path_only';
	public const CLI_OPTION_REQUIRED = 'cli.option_required';
	public const CLI_OPTION_UNSUPPORTED = 'cli.option_unsupported';
	public const CLI_PATH_OPTION_INVALID = 'cli.path_option_invalid';
	public const CLI_PROJECT_MISSING = 'cli.project_missing';
	public const CLI_STATIC_NOTICE = 'cli.static_notice';
	public const CLI_UNKNOWN_ARGUMENT = 'cli.unknown_argument';
	public const CLI_USAGE_SUGGESTION = 'cli.usage_suggestion';
	public const CLI_VERSION = 'cli.version';
	public const COMMAND_BUILD_MISSING = 'command.build_missing';
	public const COMMAND_DISABLED = 'command.disabled';
	public const COMMAND_EXTRACT_MISSING = 'command.extract_missing';
	public const COMMAND_FAILED = 'command.failed';
	public const COMMAND_HELP = 'command.help';
	public const COMMAND_PERMISSION = 'command.permission';
	public const COMMAND_USAGE_DOCTOR = 'command.usage.doctor';
	public const COMMAND_USAGE_EXTRACT = 'command.usage.extract';
	public const COMMAND_USAGE_EXTRACT_FORMAT = 'command.usage.extract_format';
	public const COMMAND_USAGE_EXTRACT_TYPE = 'command.usage.extract_type';
	public const COMMAND_USAGE_NO_ARGUMENTS = 'command.usage.no_arguments';
	public const COMMAND_USAGE_SELECTION = 'command.usage.selection';
	public const DOCTOR_CHECKING = 'doctor.checking';
	public const DOCTOR_FAILED = 'doctor.failed';
	public const DOCTOR_INVALID_SELECTION = 'doctor.invalid_selection';
	public const DOCTOR_NONE = 'doctor.none';
	public const DOCTOR_PASSED = 'doctor.passed';
	public const EXTRACT_ARCHIVED = 'extract.archived';
	public const EXTRACT_ARTIFACT_TYPE = 'extract.artifact_type';
	public const EXTRACT_DIRECTORY = 'extract.directory';
	public const EXTRACT_DUAL = 'extract.dual';
	public const EXTRACT_EXTRACTED = 'extract.extracted';
	public const EXTRACT_MISMATCH = 'extract.mismatch';
	public const EXTRACT_OUTSIDE = 'extract.outside';
	public const EXTRACT_PHAR_NOT_FOUND = 'extract.phar_not_found';
	public const EXTRACT_SAFE_NAME = 'extract.safe_name';
	public const EXTRACT_USAGE = 'extract.usage';
	public const QUEUE_BUSY = 'queue.busy';
	public const QUEUE_QUEUED = 'queue.queued';
	public const RUNTIME_DUPLICATE_SOURCES = 'runtime.duplicate_sources';
	public const RUNTIME_LANGUAGE_FALLBACK = 'runtime.language_fallback';
	public const RUNTIME_NOT_STARTED = 'runtime.not_started';
	public const RUNTIME_PLUGIN_MANIFEST_FAILED = 'runtime.plugin_manifest_failed';
	public const RUNTIME_READY = 'runtime.ready';
	public const RUNTIME_SHARED_REQUIREMENTS_FAILED = 'runtime.shared_requirements_failed';
	public const STATUS_SUMMARY = 'status.summary';
	public const SUMMARY_FILES = 'summary.files';
	public const SUMMARY_OUTPUT = 'summary.output';
	public const SUMMARY_PLUGIN_OUTPUT = 'summary.plugin_output';
	public const SUMMARY_SHADED = 'summary.shaded';
	public const SUMMARY_VIRION_OUTPUT = 'summary.virion_output';
	public const VIRIONS_LOADED = 'virions.loaded';
	public const VIRIONS_LOCAL_AMBIGUOUS_PACKAGE = 'virions.local.ambiguous_package';
	public const VIRIONS_LOCAL_LAYOUT_MISMATCH = 'virions.local.layout_mismatch';
	public const VIRIONS_LOCAL_METADATA_MISMATCH = 'virions.local.metadata_mismatch';
	public const VIRIONS_LOCAL_NAME_CONFLICT = 'virions.local.name_conflict';
	public const VIRIONS_LOCAL_SELECTED_DEBUG = 'virions.local.selected_debug';
	public const VIRIONS_LOCAL_SKIPPED_PREPARED = 'virions.local.skipped_prepared';
	public const VIRIONS_LOCAL_UNVERIFIED_NAME = 'virions.local.unverified_name';
	public const VIRIONS_NONE = 'virions.none';
	public const VIRIONS_SKIPPED_MANY = 'virions.skipped_many';
	public const VIRIONS_SKIPPED_ONE = 'virions.skipped_one';
	public const VIRIONS_STATUS_CONFLICT = 'virions.status.conflict';
	public const VIRIONS_STATUS_FAILED = 'virions.status.failed';
	public const VIRIONS_STATUS_LOADED = 'virions.status.loaded';
	public const VIRIONS_STATUS_SKIPPED = 'virions.status.skipped';
	public const WORKER_INVALID_BUILD_PAYLOAD = 'worker.invalid_build_payload';
	public const WORKER_INVALID_EXTRACT_PAYLOAD = 'worker.invalid_extract_payload';
	public const WORKER_PATH_MISSING = 'worker.path_missing';
	public const WORKER_UNKNOWN_OPERATION = 'worker.unknown_operation';
}
