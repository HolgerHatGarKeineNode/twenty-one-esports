/**
 * What the player's signer can do, without loading any crypto: a signer
 * that can encrypt and decrypt NIP-44 carries the end-to-end encrypted
 * chats (NIP-17), and with them the lobby cards of a casual 1v1 (P23).
 */
export function canEncrypt(signer) {
    return typeof signer?.signEvent === 'function' && typeof signer?.nip44?.encrypt === 'function' && typeof signer?.nip44?.decrypt === 'function';
}
