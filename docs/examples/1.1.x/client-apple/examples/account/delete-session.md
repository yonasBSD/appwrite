import Appwrite

func main() async throws {
    let client = Client()
      .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
      .setProject("5df5acd0d48c2") // Your project ID
    let account = Account(client)
    let result = try await account.deleteSession(
        sessionId: "[SESSION_ID]"
    )

    print(String(describing: result)
}
