import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val messaging = Messaging(client)

val response = messaging.updateApnsProvider(
    providerId = "<PROVIDER_ID>",
    name = "<NAME>", // optional
    enabled = false, // optional
    authKey = "<AUTH_KEY>", // optional
    authKeyId = "<AUTH_KEY_ID>", // optional
    teamId = "<TEAM_ID>", // optional
    bundleId = "<BUNDLE_ID>", // optional
    sandbox = false // optional
)
