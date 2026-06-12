@extends('layouts.profile')

@section('header')
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        {{ __('Organization Users') }}
    </h2>
@endsection

@section('content')
    <div class="p-6" x-data="{ showInviteModal: false }">
        @if($organizations->isEmpty())
            <div class="text-center py-8">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                </svg>
                <h3 class="mt-2 text-sm font-medium text-gray-900">{{ __('profile.no_organization_membership') }}</h3>
                <p class="mt-1 text-gray-500">{{ __('profile.you_not_member_of_organization') }}</p>
                <div class="mt-6">
                    <a href="{{ route('profile.organization') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        {{ __('profile.create_organization') }}
                    </a>
                </div>
            </div>
        @else


            <!-- Users List -->
            <div class="mb-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('profile.organization_members') }}</h3>

                @if($organizationUsers->isEmpty())
                    <div class="text-center py-8">
                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                        </svg>
                        <h3 class="mt-2 text-sm font-medium text-gray-900">{{ __('profile.no_members') }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ __('profile.no_members_yet') }}</p>
                    </div>
                @else
                    {{-- Mobile Cards --}}
                    <div class="sm:hidden space-y-3">
                        @foreach($organizationUsers as $orgUser)
                            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10">
                                            <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center">
                                                <span class="text-sm font-medium text-indigo-600">
                                                    {{ strtoupper(substr($orgUser->name, 0, 2)) }}
                                                </span>
                                            </div>
                                        </div>
                                        <div class="ml-3">
                                            <div class="text-sm font-medium text-gray-900">{{ $orgUser->name }}</div>
                                            <div class="text-xs text-gray-500">{{ $orgUser->email }}</div>
                                        </div>
                                    </div>
                                    @if($orgUser->pivot->role === \App\Enums\OrganizationRole::Owner)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                            {{ $orgUser->pivot->role->label() }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            {{ $orgUser->pivot->role->label() }}
                                        </span>
                                    @endif
                                </div>
                                <div class="text-sm text-gray-500 space-y-1">
                                    @if($orgUser->id === $user->id)
                                        <div class="text-xs text-gray-400 italic">{{ __('profile.you') }}</div>
                                    @endif
                                    <div>{{ __('profile.joined_at') }}: {{ \Carbon\Carbon::parse($orgUser->pivot->joined_at)->format('M j, Y') }}</div>
                                </div>
                                @if($isAdmin && $orgUser->id !== $user->id)
                                    <div class="flex flex-wrap gap-3 mt-3 pt-2 border-t border-gray-100">
                                        @if($orgUser->pivot->role !== \App\Enums\OrganizationRole::Owner)
                                            <form method="post" action="{{ route('profile.organization.users.make-admin', $orgUser) }}" class="inline">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="text-sm text-indigo-600 hover:text-indigo-900">
                                                    {{ __('profile.make_admin') }}
                                                </button>
                                            </form>
                                        @else
                                            <form method="post" action="{{ route('profile.organization.users.make-member', $orgUser) }}" class="inline">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="text-sm text-orange-600 hover:text-orange-900">
                                                    {{ __('profile.make_member') }}
                                                </button>
                                            </form>
                                        @endif
                                        <form method="post" action="{{ route('profile.organization.users.remove', $orgUser) }}" class="inline" onsubmit="return confirm('{{ __('profile.confirm_remove_user') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-sm text-red-600 hover:text-red-900">
                                                {{ __('profile.remove') }}
                                            </button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    {{-- Desktop Table --}}
                    <div class="hidden sm:block bg-white shadow overflow-hidden sm:rounded-md">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('profile.user') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('profile.user_role') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('profile.joined_at') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('profile.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($organizationUsers as $orgUser)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-10 w-10">
                                                        <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center">
                                                            <span class="text-sm font-medium text-indigo-600">
                                                                {{ strtoupper(substr($orgUser->name, 0, 2)) }}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-gray-900">{{ $orgUser->name }}</div>
                                                        <div class="text-sm text-gray-500">{{ $orgUser->email }}</div>
                                                        @if($orgUser->id === $user->id)
                                                            <div class="text-xs text-gray-400 italic">{{ __('profile.you') }}</div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                @if($orgUser->pivot->role === \App\Enums\OrganizationRole::Owner)
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                        {{ $orgUser->pivot->role->label() }}
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                        {{ $orgUser->pivot->role->label() }}
                                                    </span>
                                                @endif
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {{ \Carbon\Carbon::parse($orgUser->pivot->joined_at)->format('M j, Y') }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                @if($isAdmin && $orgUser->id !== $user->id)
                                                    <div class="flex space-x-2">
                                                        @if($orgUser->pivot->role !== \App\Enums\OrganizationRole::Owner)
                                                            <form method="post" action="{{ route('profile.organization.users.make-admin', $orgUser) }}" class="inline">
                                                                @csrf
                                                                @method('PATCH')
                                                                <button type="submit" class="text-indigo-600 hover:text-indigo-900">
                                                                    {{ __('profile.make_admin') }}
                                                                </button>
                                                            </form>
                                                        @else
                                                            <form method="post" action="{{ route('profile.organization.users.make-member', $orgUser) }}" class="inline">
                                                                @csrf
                                                                @method('PATCH')
                                                                <button type="submit" class="text-orange-600 hover:text-orange-900">
                                                                    {{ __('profile.make_member') }}
                                                                </button>
                                                            </form>
                                                        @endif
                                                        <form method="post" action="{{ route('profile.organization.users.remove', $orgUser) }}" class="inline" onsubmit="return confirm('{{ __('profile.confirm_remove_user') }}')">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="text-red-600 hover:text-red-900">
                                                                {{ __('profile.remove') }}
                                                            </button>
                                                        </form>
                                                    </div>
                                                @else
                                                    <span class="text-gray-400">-</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>

            <!-- Status Messages -->
            @if (session('status') === 'user-made-admin')
                <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 0116 0zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-green-800">
                                {{ __('profile.user_made_admin_successfully') }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            @if (session('status') === 'invitation-sent')
                <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 0016 0zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-green-800">
                                {{ __('profile.invitation_sent_successfully') }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            @if (session('status') === 'invitation-revoked')
                <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 0016 0zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-green-800">
                                {{ __('profile.invitation_revoked_successfully') }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            @if (session('status') === 'invitation-resent')
                <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 0016 0zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-green-800">
                                {{ __('profile.invitation_resent_successfully') }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            @if (session('status') === 'invitation-accepted')
                <div class="mb-6 bg-green-50 border border-green-200 rounded-md p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 0016 0zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-green-800">
                                {{ __('profile.welcome_to_organization') }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 bg-red-50 border border-red-200 rounded-md p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 0116 0zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-red-800">
                                {{ session('error') }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Pending Invitations -->
            @if($isAdmin && $pendingInvitations->count() > 0)
                <div class="mb-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('profile.pending_invitations_count', ['count' => $pendingInvitations->count()]) }}</h3>
                    {{-- Mobile Cards --}}
                    <div class="sm:hidden space-y-3">
                        @foreach($pendingInvitations as $invitation)
                            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-medium text-gray-900">{{ $invitation->email }}</span>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        {{ \App\Enums\OrganizationRole::from($invitation->role)->label() }}
                                    </span>
                                </div>
                                <div class="text-sm text-gray-500 space-y-1">
                                    <div>{{ __('profile.invited_by') }}: {{ $invitation->invitedBy->name }}</div>
                                    <div>{{ __('profile.expires') }}: {{ $invitation->expires_at->diffForHumans() }}</div>
                                </div>
                                <div class="flex gap-3 mt-3 pt-2 border-t border-gray-100">
                                    <form method="POST" action="{{ route('profile.organization.invitations.resend', $invitation) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-sm text-indigo-600 hover:text-indigo-900">
                                            {{ __('profile.resend') }}
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('profile.organization.invitations.revoke', $invitation) }}" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-sm text-red-600 hover:text-red-900" onclick="return confirm('{{ __('profile.revoke_invitation_confirm') }}')">
                                            {{ __('profile.revoke') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Desktop Table --}}
                    <div class="hidden sm:block bg-white shadow overflow-hidden sm:rounded-md">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('profile.email_address') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('profile.user_role') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('profile.invited_by') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('profile.expires') }}</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">{{ __('profile.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($pendingInvitations as $invitation)
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900">{{ $invitation->email }}</div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    {{ \App\Enums\OrganizationRole::from($invitation->role)->label() }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {{ $invitation->invitedBy->name }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {{ $invitation->expires_at->diffForHumans() }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <div class="flex space-x-3">
                                                    <form method="POST" action="{{ route('profile.organization.invitations.resend', $invitation) }}" class="inline">
                                                        @csrf
                                                        <button type="submit" class="text-indigo-600 hover:text-indigo-900">
                                                            {{ __('profile.resend') }}
                                                        </button>
                                                    </form>
                                                    <form method="POST" action="{{ route('profile.organization.invitations.revoke', $invitation) }}" class="inline">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="text-red-600 hover:text-red-900" onclick="return confirm('{{ __('profile.revoke_invitation_confirm') }}')">
                                                            {{ __('profile.revoke') }}
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Admin Actions -->
            @if($isAdmin)
                <div class="border-t pt-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">{{ __('profile.admin_actions') }}</h3>
                    <div class="flex flex-col space-y-3">
                        @if(auth()->user()->isFreeTier())
                            {{-- Free tier restriction --}}
                            <div>
                                <button
                                    type="button"
                                    disabled
                                    class="inline-flex items-center px-4 py-2 bg-gray-300 border border-transparent rounded-md font-semibold text-xs text-gray-500 uppercase tracking-widest cursor-not-allowed"
                                >
                                    {{ __('profile.invite_user') }}
                                </button>
                            </div>
                            <div class="p-3 bg-amber-50 border border-amber-200 rounded-md max-w-md">
                                <p class="text-sm text-amber-800">
                                    {{ __('common.upgrade_required') }}
                                    <a href="{{ url('/pricing') }}" class="font-medium text-amber-900 underline hover:text-amber-700">
                                        {{ __('common.view_pricing') }}
                                    </a>
                                </p>
                            </div>
                        @else
                            <div class="flex space-x-4">
                                <button @click="showInviteModal = true" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                                    {{ __('profile.invite_user') }}
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            <!-- Invite User Modal -->
            <div x-show="showInviteModal"
                 x-cloak
                 class="fixed z-10 inset-0 overflow-y-auto"
                 aria-labelledby="modal-title"
                 role="dialog"
                 aria-modal="true"
                 style="display: none;">
                <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                    <!-- Background overlay -->
                    <div x-show="showInviteModal"
                         x-transition:enter="ease-out duration-300"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         x-transition:leave="ease-in duration-200"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                         aria-hidden="true"
                         @click="showInviteModal = false"></div>

                    <!-- Modal panel -->
                    <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                    <div x-show="showInviteModal"
                         x-transition:enter="ease-out duration-300"
                         x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                         x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                         x-transition:leave="ease-in duration-200"
                         x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                         x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                         class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">

                        <form method="POST" action="{{ route('profile.organization.users.invite') }}">
                            @csrf
                            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                <div class="sm:flex sm:items-start">
                                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-indigo-100 sm:mx-0 sm:h-10 sm:w-10">
                                        <svg class="h-6 w-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 19v-8.93a2 2 0 01.89-1.664l7-4.666a2 2 0 012.22 0l7 4.666A2 2 0 0121 10.07V19M3 19a2 2 0 002 2h14a2 2 0 002-2M3 19l6.75-4.5M21 19l-6.75-4.5M3 10l6.75 4.5M21 10l-6.75 4.5m0 0l-1.14.76a2 2 0 01-2.22 0l-1.14-.76"></path>
                                        </svg>
                                    </div>
                                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                                        <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                                            {{ __('profile.invite_user_to_organization') }}
                                        </h3>
                                        <div class="mt-4 space-y-4">
                                            <div>
                                                <label for="email" class="block text-sm font-medium text-gray-700">{{ __('profile.email_address') }}</label>
                                                <input type="email"
                                                       name="email"
                                                       id="email"
                                                       required
                                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                       placeholder="user@example.com">
                                            </div>
                                            <div>
                                                <label for="role" class="block text-sm font-medium text-gray-700">{{ __('profile.user_role') }}</label>
                                                <select name="role"
                                                        id="role"
                                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                                    <option value="editor">{{ __('profile.member') }}</option>
                                                    <option value="owner">{{ __('profile.admin') }}</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                                <button type="submit"
                                        class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:ml-3 sm:w-auto sm:text-sm">
                                    {{ __('profile.send_invitation') }}
                                </button>
                                <button type="button"
                                        @click="showInviteModal = false"
                                        class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                                    {{ __('profile.cancel') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
