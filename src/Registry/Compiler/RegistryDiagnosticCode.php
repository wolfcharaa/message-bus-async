<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Registry\Compiler;

enum RegistryDiagnosticCode: string
{
    case ClassMissing = 'class.missing';
    case ClassDuplicate = 'class.duplicate';
    case MessageKindAmbiguous = 'message.kind_ambiguous';
    case MessageAliasInvalidTarget = 'message.alias_invalid_target';
    case MessageAliasMissing = 'message.alias_missing';
    case MessageAliasMultiple = 'message.alias_multiple';
    case MessageAliasEmpty = 'message.alias_empty';
    case MessageAliasDuplicate = 'message.alias_duplicate';
    case MessageAliasRequired = 'message.alias_required';
    case HandlerClassNotInstantiable = 'handler.class_not_instantiable';
    case HandlerAttributeInvalid = 'handler.attribute_invalid';
    case HandlerAttributeUnsupported = 'handler.attribute_unsupported';
    case BindingIdMissing = 'binding.id_missing';
    case BindingIdDuplicate = 'binding.id_duplicate';
    case BindingMessageMissing = 'binding.message_missing';
    case BindingMessageNotListed = 'binding.message_not_listed';
    case BindingMessageKindMismatch = 'binding.message_kind_mismatch';
    case FlowMissing = 'flow.missing';
    case FlowInvalid = 'flow.invalid';
    case HandlerMethodMissing = 'handler.method_missing';
    case HandlerMethodNotCallable = 'handler.method_not_callable';
    case HandlerSignatureInvalid = 'handler.signature_invalid';
    case InterceptorMissing = 'interceptor.missing';
    case InterceptorInvalid = 'interceptor.invalid';
    case QueryHandlerCount = 'query.handler_count';
    case QueryAsyncFlow = 'query.async_flow';
    case CommandPrimaryMissing = 'command.primary_missing';
    case CommandPrimaryDuplicate = 'command.primary_duplicate';
}
