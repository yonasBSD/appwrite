import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Messaging

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val messaging = Messaging(client)

val response = messaging.createSms(
    messageId = "<MESSAGE_ID>",
    content = "<CONTENT>",
    topics = listOf(), // optional
    users = listOf(), // optional
    targets = listOf(), // optional
    draft = false, // optional
    scheduledAt = "" // optional
)
