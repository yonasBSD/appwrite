import Appwrite

let client = Client()
    .setEndpoint("https://<REGION>.cloud.appwrite.io/v1") // Your API Endpoint
    .setProject("<YOUR_PROJECT_ID>") // Your project ID

let teams = Teams(client)

let membership = try await teams.getMembership(
    teamId: "<TEAM_ID>",
    membershipId: "<MEMBERSHIP_ID>"
)

