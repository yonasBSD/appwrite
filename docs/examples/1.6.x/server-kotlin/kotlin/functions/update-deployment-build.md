import io.appwrite.Client
import io.appwrite.coroutines.CoroutineCallback
import io.appwrite.services.Functions

val client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID
    .setKey("<YOUR_API_KEY>") // Your secret API key

val functions = Functions(client)

val response = functions.updateDeploymentBuild(
    functionId = "<FUNCTION_ID>",
    deploymentId = "<DEPLOYMENT_ID>"
)
