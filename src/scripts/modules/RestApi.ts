export default class RestApi {
    public static getWpNonce(): string | undefined {
        return window.flareloadConfig?.restNonce;
    }
}