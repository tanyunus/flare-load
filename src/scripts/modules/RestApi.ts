export default class RestApi {
    public static async getWpNonce(): Promise<string | undefined> {
        try {
            const response = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce');

            if (response.ok) {
                return await response.text();
            }

            return undefined;
        } catch (error) {
            console.error('Error getting WP nonce:', error);
            return undefined;
        }
    }
}